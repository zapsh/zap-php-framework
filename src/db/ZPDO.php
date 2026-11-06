<?php

namespace zap\db;

use PDO;
use PDOException;
use PDOStatement;
use zap\exception\NotSupportedException;
use zap\util\Arr;

/**
 * ZPDO（支持 MySQL / MariaDB / SQLite / PostgreSQL）
 *
 */
class ZPDO
{
    protected string $tablePrefix;

    public string $driver;

    public int $rowCount = 0;

    /** @var array Query log entries */
    protected $queryLog = [];

    /** @var bool Whether query logging is enabled */
    protected $logging = false;

    /** @var int Bind parameter counter (avoids collision with Random::rand) */
    protected $bindCounter = 0;

    /** @var PDO 真实底层连接（可能是 Pdo\Mysql / Pdo\Sqlite / Pdo\Pgsql 或普通 PDO） */
    protected PDO $connection;

    public function __construct(array $config)
    {
        $this->tablePrefix = $config['prefix'] ?? '';
        $this->driver      = strtolower($config['driver'] ?? 'mysql');
        $dsn               = $config['dsn'] ?? $this->buildDSN($config);
        $username          = $config['username'] ?? $config['user'] ?? null;
        $password          = $config['password'] ?? null;
        $options           = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($this->driver === 'mysql' || $this->driver === 'mariadb') {
            $dbCharset = $config['charset'] ?? 'utf8';
            $dbCollate = $config['collate'] ?? null;
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] =
                "SET NAMES '{$dbCharset}' " . ($dbCollate ? " COLLATE '{$dbCollate}'" : '');
        }

        $options += Arr::get($config, 'options', []);

        // 由各驱动封装类（或本类）负责创建真实连接
        $this->connection = $this->createConnection($dsn, $username, $password, $options);

        // SQLite 注册 REGEXP 自定义函数（PHP 8.4+ 走 Pdo\Sqlite::createFunction）
        if ($this->driver === 'sqlite') {
            $regexp = function ($pattern, $subject) {
                return preg_match("/{$pattern}/i", $subject);
            };
            if ($this->connection instanceof \Pdo\Sqlite) {
                $this->connection->createFunction('REGEXP', $regexp, 2);
            } elseif (method_exists($this->connection, 'sqliteCreateFunction')) {
                $this->connection->sqliteCreateFunction('REGEXP', $regexp, 2);
            }
        }

        $this->connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [Statement::class]);
    }

    /**
     * 创建底层真实 PDO 连接。
     *
     */
    protected function createConnection(string $dsn, ?string $username, ?string $password, ?array $options): PDO
    {
        return new PDO($dsn, $username, $password, $options);
    }

    /**
     * 按配置创建对应的驱动封装实例（推荐入口）。
     */
    public static function connect(array $config): ZPDO
    {
        $driver = strtolower($config['driver'] ?? 'mysql');
        return match ($driver) {
            'mysql', 'mariadb'            => new ZPDOMySql($config),
            'sqlite'                     => new ZPDOSqlite($config),
            'pgsql', 'postgres', 'postgresql' => new ZPDOPgsql($config),
            default                      => new ZPDO($config),
        };
    }

    /**
     * 获取底层真实 PDO 连接（必要时用于驱动专属能力）。
     */
    public function getUnderlyingPDO(): PDO
    {
        return $this->connection;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    // ─── DSN ──────────────────────────────────────────────────

    private function buildDSN(&$config): string
    {
        $dsnElements = [];
        switch ($this->driver) {
            case 'mysql':
            case 'mariadb':
                $dsnElements = Arr::find($config, ['host', 'port', 'dbname', 'unix_socket', 'charset']);
                break;
            case 'pgsql':
                $dsnElements = Arr::find($config, ['host', 'port', 'dbname', 'user', 'password', 'sslmode']);
                unset($config['user'], $config['password']);
                break;
            case 'sqlite':
                trigger_error('Please directly set the DSN parameters', E_USER_ERROR);
                break;
            default:
                trigger_error("{$this->driver} driver not supported", E_USER_ERROR);
        }

        return $this->driver . ':' . http_build_query($dsnElements, '', ';');
    }

    // ─── PDO 方法委托 ─────────────────────────────────────────

    public function prepare($query, $options = []): PDOStatement|false
    {
        $sql = $this->prepareSQL($query);
        $stm = $this->connection->prepare($sql, $options);

        // 绝大多数业务查询走 prepare() + execute()（Query / Model / CRUD）。
        // 开启日志时给 statement 注入回调，由 Statement::execute() 记录真实参数与耗时。
        if ($stm instanceof Statement && $this->logging) {
            $stm->setQueryLogger(function (string $executedSql, array $params, float $durationMs) use ($sql) {
                $this->logQuery($executedSql !== '' ? $executedSql : $sql, $params, $durationMs);
            });
        }

        return $stm;
    }

    public function exec($statement): int|false
    {
        $start = microtime(true);
        try {
            $result = $this->connection->exec($this->prepareSQL($statement));
            $this->rowCount = $result;
            return $result;
        } finally {
            $this->logQuery($statement, [], (microtime(true) - $start) * 1000);
        }
    }

    /**
     * Execute an SQL statement and return the PDOStatement.
     *
     * When $params is empty, delegates to underlying PDO::query() for direct execution.
     * When $params is provided, prepares and executes with parameter binding.
     *
     * @param string $query SQL query
     * @param array  $params Parameters for prepared statement
     * @param mixed  ...$fetch_mode_args PDO fetch modes (only used when no params)
     * @return PDOStatement|false
     */
    public function query($query, $params = [], ...$fetch_mode_args)
    {
        $sql = $this->prepareSQL($query);

        if (empty($params)) {
            // No params → delegate to underlying PDO::query for direct execution
            $start = microtime(true);
            try {
                if (empty($fetch_mode_args)) {
                    $stm = $this->connection->query($sql);
                } else {
                    $stm = $this->connection->query($sql, ...$fetch_mode_args);
                }
            } finally {
                $this->logQuery($sql, [], (microtime(true) - $start) * 1000);
            }
        } else {
            // With params → prepare + execute（日志由 Statement::execute() 记录，避免重复）
            $stm = $this->prepare($sql);
            $stm->execute($params);
            // Apply fetch mode if specified
            if (!empty($fetch_mode_args)) {
                $stm->setFetchMode(...$fetch_mode_args);
            }
        }

        $this->rowCount = $stm->rowCount();
        return $stm;
    }

    public function quote($value, $type = PDO::PARAM_STR): string|false
    {
        return $this->connection->quote((string) $value, $type);
    }

    public function lastInsertId($name = null)
    {
        return $this->connection->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollBack(): bool
    {
        return $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function errorInfo(): array
    {
        return $this->connection->errorInfo();
    }

    public function errorCode(): ?string
    {
        return $this->connection->errorCode();
    }

    public function getAttribute(int $attribute)
    {
        return $this->connection->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, $value): bool
    {
        return $this->connection->setAttribute($attribute, $value);
    }

    /**
     * 转发驱动专属方法（如 Pdo\Sqlite::createFunction / Pdo\Mysql::getWarningInfo 等）。
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->connection, $name)) {
            return $this->connection->$name(...$arguments);
        }
        throw new \BadMethodCallException("Method [{$name}] is not defined on ZPDO or the underlying PDO driver.");
    }

    /**
     * Execute a SELECT and return all rows as array.
     */
    public function select(string $query, array $params = [], int $fetchMode = \PDO::FETCH_ASSOC): array
    {
        $stm = $this->prepare($query);
        $stm->execute($params);
        $this->rowCount = $stm->rowCount();
        return $stm->fetchAll($fetchMode);
    }

    // ─── CRUD Operations ───────────────────────────────────────

    /**
     * Insert a single row.
     */
    public function insert(string $table, array $data)
    {
        $names        = [];
        $placeholders = [];
        $params       = [];

        foreach ($data as $name => $value) {
            $names[] = $this->quoteColumn($name);
            if ($value instanceof Expr) {
                $placeholders[] = $value->raw;
            } else {
                $bind = $this->nextBind();
                $placeholders[] = ':' . $bind;
                $params[$bind]  = $value;
            }
        }

        $sql = 'INSERT INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $names) . ') VALUES ('
            . implode(', ', $placeholders) . ')';

        // 日志由 Statement::execute() 记录（含真实绑定参数），此处不再重复记录
        $stm = $this->prepare($sql);
        $stm->execute($params);
        return $this->lastInsertId();
    }

    /**
     * Batch insert multiple rows.
     *
     * @param string $table
     * @param array  $rows  Array of associative arrays
     * @return int   Number of affected rows
     */
    public function batchInsert(string $table, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $first    = reset($rows);
        $columns  = array_keys($first);
        $names    = array_map([$this, 'quoteColumn'], $columns);
        $params   = [];
        $allPlaceholders = [];

        foreach ($rows as $rowIndex => $row) {
            $rowPlaceholders = [];
            foreach ($columns as $col) {
                $bind = ':' . $this->nextBind();
                $rowPlaceholders[] = $bind;
                $params[$bind]     = $row[$col] ?? null;
            }
            $allPlaceholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = 'INSERT INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $names) . ') VALUES '
            . implode(', ', $allPlaceholders);

        $stm = $this->prepare($sql);
        $stm->execute($params);
        $this->rowCount = $stm->rowCount();
        return $stm->rowCount();
    }

    /**
     * Upsert（插入或更新）。
     *
     * 各驱动实现：
     *  - MySQL / MariaDB：INSERT ... ON DUPLICATE KEY UPDATE
     *  - PostgreSQL     ：INSERT ... ON CONFLICT (target) DO UPDATE SET
     *  - SQLite 3.24+   ：INSERT ... ON CONFLICT [(target)] DO UPDATE SET col=excluded.col
     *
     * 相比 REPLACE INTO，UPSERT 不会删除旧行再插入（避免 rowid 变化、外键 ON DELETE
     * 触发、触发器误触发等问题），是更稳妥的「存在则更新」实现。
     *
     * @param array      $data        插入数据（列 => 值）
     * @param array|null $duplicate   冲突时更新的列（列 => 值/Expr）；传 null 则退化为纯插入
     * @param mixed      $primaryKeys 冲突目标列（仅 pgsql / sqlite 需要；数组或字符串）
     */
    public function upsert($table, $data, $duplicate = null, $primaryKeys = null)
    {
        $params       = [];
        $names        = [];
        $placeholders = [];

        foreach ($data as $name => $value) {
            $col = $this->quoteColumn($name);
            $names[] = $col;
            if ($value instanceof Expr) {
                $placeholders[] = $value->raw;
            } else {
                $bind = $this->nextBind();
                $placeholders[] = ':' . $bind;
                $params[$bind]  = $value;
            }
        }

        $sql = 'INSERT INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $names) . ') VALUES ('
            . implode(', ', $placeholders) . ')';

        if (empty($duplicate)) {
            // 无冲突更新逻辑：纯插入（不存在才插入）
            $stm = $this->prepare($sql);
            $stm->execute($params);
            return $this->lastInsertId() ?: true;
        }

        switch ($this->driver) {
            case 'mysql':
            case 'mariadb':
                $dupSet = [];
                foreach ($duplicate as $name => $value) {
                    $col = $this->quoteColumn($name);
                    if ($value instanceof Expr) {
                        $dupSet[] = $col . '=' . $value->raw;
                    } else {
                        $bind = $this->nextBind();
                        $dupSet[] = $col . '=:' . $bind;
                        $params[$bind] = $value;
                    }
                }
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $dupSet);
                break;

            case 'pgsql':
                if ($primaryKeys === null) {
                    reset($data);
                    $primaryKeys = $this->quoteColumn(key($data));
                } elseif (is_array($primaryKeys)) {
                    $primaryKeys = implode(',', array_map([$this, 'quoteColumn'], $primaryKeys));
                }
                $dupSet = [];
                foreach ($duplicate as $name => $value) {
                    $col = $this->quoteColumn($name);
                    if ($value instanceof Expr) {
                        $dupSet[] = $col . '=' . $value->raw;
                    } else {
                        $bind = $this->nextBind();
                        $dupSet[] = $col . '=:' . $bind;
                        $params[$bind] = $value;
                    }
                }
                $sql .= ' ON CONFLICT (' . $primaryKeys . ') DO UPDATE SET ' . implode(',', $dupSet);
                break;

            case 'sqlite':
                // SQLite 3.24+ UPSERT：用 excluded.<col> 引用「待插入」的值，
                // 因此不再单独绑定 duplicate 的值（避免多出无对应占位符的绑定参数）。
                if ($primaryKeys === null) {
                    $sql .= ' ON CONFLICT DO UPDATE SET ';
                } else {
                    $conflict = is_array($primaryKeys)
                        ? implode(',', array_map([$this, 'quoteColumn'], $primaryKeys))
                        : $this->quoteColumn($primaryKeys);
                    $sql .= ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ';
                }
                $excludedSet = [];
                foreach (array_keys($duplicate) as $name) {
                    $col = $this->quoteColumn($name);
                    $excludedSet[] = $col . '=excluded.' . $col;
                }
                $sql .= implode(',', $excludedSet);
                break;

            default:
                throw new NotSupportedException('Upsert only supports MySQL/MariaDB, PostgreSQL and SQLite');
        }

        $stm = $this->prepare($sql);
        $stm->execute($params);
        return $this->lastInsertId() ?: true;
    }

    /**
     * REPLACE INTO operation.
     */
    public function replace(string $table, array $data): int
    {
        $params       = [];
        $names        = [];
        $placeholders = [];

        foreach ($data as $name => $value) {
            $names[] = $this->quoteColumn($name);
            if ($value instanceof Expr) {
                $placeholders[] = $value->raw;
            } else {
                $bind = $this->nextBind();
                $placeholders[] = ':' . $bind;
                $params[$bind]  = $value;
            }
        }

        $sql = 'REPLACE INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $names) . ') VALUES ('
            . implode(', ', $placeholders) . ')';

        $stm = $this->prepare($sql);
        $stm->execute($params);
        $this->rowCount = $stm->rowCount();
        return $stm->rowCount();
    }

    /**
     * Update rows.
     */
    public function update(string $table, array $data, $conditions = '', array $params = []): int
    {
        $placeholders = [];
        $inputParams  = [];

        foreach ($data as $name => $value) {
            if ($value instanceof Expr) {
                $placeholders[] = $this->quoteColumn($name) . '=' . $value->raw;
            } else {
                $placeholders[] = $this->quoteColumn($name) . '=:' . $name;
                $inputParams[$name] = $value;
            }
        }

        $sql = 'UPDATE ' . $this->quoteTable($table) . ' SET ' . implode(', ', $placeholders);

        if (($where = $this->prepareConditions($conditions, $params, $inputParams)) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stm = $this->prepare($sql);
        $stm->execute($inputParams);
        $this->rowCount = $stm->rowCount();
        return $stm->rowCount();
    }

    /**
     * Delete rows.
     */
    public function delete(string $table, $conditions = '', array $params = []): int
    {
        $sql         = 'DELETE FROM ' . $this->quoteTable($table);
        $inputParams = [];

        if (($where = $this->prepareConditions($conditions, $params, $inputParams)) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stm = $this->prepare($sql);
        $stm->execute($inputParams);
        $this->rowCount = $stm->rowCount();
        return $stm->rowCount();
    }

    /**
     * Count rows.
     */
    public function count(string $table, $conditions = '', array $params = [])
    {
        $sql         = 'SELECT COUNT(*) AS rowcount FROM ' . $this->quoteTable($table);
        $inputParams = [];

        if (($where = $this->prepareConditions($conditions, $params, $inputParams)) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stm = $this->prepare($sql);
        $stm->execute($inputParams);
        return $stm->fetchColumn();
    }

    /**
     * Key-value pair query.
     */
    public function keyPair(string $table, $columns, $conditions = '', array $params = []): array
    {
        if (is_array($columns)) {
            $columns = implode(',', array_map([$this, 'quoteColumn'], $columns));
        }

        $sql         = 'SELECT ' . $columns . ' FROM ' . $this->quoteTable($table);
        $inputParams = [];

        if (($where = $this->prepareConditions($conditions, $params, $inputParams)) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stm = $this->prepare($sql);
        $stm->execute($inputParams);
        $this->rowCount = $stm->rowCount();
        return $stm->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // ─── Convenience Methods ───────────────────────────────────

    /**
     * Fetch all rows.
     */
    public function getAll(string $statement, array $params = [], $fetchMode = null): array
    {
        $stm = $this->prepare($statement);
        $stm->execute($params);
        return $stm->fetchAll($fetchMode);
    }

    /**
     * Fetch a single row.
     */
    public function get(string $statement, array $params = [], $fetchMode = null)
    {
        $stm = $this->prepare($statement);
        $stm->execute($params);
        return $stm->fetch($fetchMode);
    }

    /**
     * Fetch a single column value.
     */
    public function value(string $statement, array $params = [])
    {
        $stm = $this->prepare($statement);
        $stm->execute($params);
        return $stm->fetchColumn();
    }

    // ─── Query Builder Factory ─────────────────────────────────

    public function table(string $table, ?string $alias = null): Query
    {
        $query = new Query($this);
        return $query->from($table, $alias ?? '');
    }

    // ─── Schema / DDL ──────────────────────────────────────────

    public function rawExec($statement)
    {
        return $this->connection->exec($statement);
    }

    public function renameTable($oldName, $newName)
    {
        return $this->exec('RENAME TABLE ' . $this->quoteTable($oldName) . ' TO ' . $this->quoteTable($newName));
    }

    public function dropTable($table)
    {
        return $this->exec('DROP TABLE ' . $this->quoteTable($table));
    }

    public function truncateTable($table)
    {
        if ($this->driver === 'sqlite') {
            // SQLite 不支持 TRUNCATE，用 DELETE + 重置序列替代
            $this->exec('DELETE FROM ' . $this->quoteTable($table));
            try {
                $this->exec("DELETE FROM sqlite_sequence WHERE name = " . $this->quote($this->unPrefixTable($table)));
            } catch (PDOException $e) {
                // sqlite_sequence 仅在有 AUTOINCREMENT 表时才存在，忽略
            }
            return true;
        }
        return $this->exec('TRUNCATE TABLE ' . $this->quoteTable($table));
    }

    public function getTables(): array
    {
        if ($this->driver === 'sqlite') {
            return $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        if ($this->driver === 'mysql' || $this->driver === 'mariadb') {
            return $this->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        }
        if ($this->driver === 'pgsql') {
            return $this->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        return [];
    }

    public function getTableStructure(string $table): string
    {
        $tbl = $this->quoteTable($table);
        if ($this->driver === 'sqlite') {
            return (string) $this->query(
                "SELECT sql FROM sqlite_master WHERE name=?",
                [$this->unPrefixTable($table)]
            )->fetchColumn();
        }
        if ($this->driver === 'mysql' || $this->driver === 'mariadb') {
            return (string) $this->query("SHOW CREATE TABLE {$tbl}")->fetchColumn(1);
        }
        if ($this->driver === 'pgsql') {
            return (string) $this->query("SELECT pg_get_serial_sequence({$this->quote($this->unPrefixTable($table))}, 'id')")
                ->fetchColumn();
        }
        return '';
    }

    public function getTableColumns(string $table): array
    {
        $tbl = $this->quoteTable($table);
        if ($this->driver === 'sqlite') {
            return $this->query("PRAGMA table_info({$tbl})")->fetchAll();
        }
        if ($this->driver === 'mysql' || $this->driver === 'mariadb') {
            return $this->query("SHOW COLUMNS FROM {$tbl}")->fetchAll();
        }
        if ($this->driver === 'pgsql') {
            return $this->query(
                "SELECT column_name AS Field, data_type AS Type, is_nullable AS Null, column_default AS Default "
                . "FROM information_schema.columns WHERE table_name = " . $this->quote($this->unPrefixTable($table))
            )->fetchAll();
        }
        return [];
    }

    // ─── Transaction Helpers ───────────────────────────────────

    /**
     * Begin a transaction on this connection.
     */
    public function beginTrans(): bool
    {
        return $this->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commitTrans(): bool
    {
        return $this->commit();
    }

    /**
     * Rollback the current transaction.
     */
    public function rollbackTrans(): bool
    {
        return $this->rollBack();
    }

    // ─── Query Logging ─────────────────────────────────────────

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    public function disableQueryLog(): void
    {
        $this->logging = false;
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    protected function logQuery(string $sql, array $params, ?float $duration = null): void
    {
        if ($this->logging) {
            $this->queryLog[] = [
                'sql'      => $sql,
                'params'   => $params,
                'time'     => microtime(true),
                'duration' => $duration,
            ];
        }
    }

    // ─── Quote / Prefix ────────────────────────────────────────

    public function setTablePrefix($prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    public function unPrefixTable($table): string
    {
        if ($this->tablePrefix && str_starts_with($table, $this->tablePrefix)) {
            return substr($table, strlen($this->tablePrefix));
        }
        return $table;
    }

    public function prepareSQL($sql): string
    {
        if ($this->tablePrefix) {
            return preg_replace_callback('/\{([\w\-\. ]+?)\}/', function ($matches) {
                return $this->quoteTable($matches[1]);
            }, $sql);
        }
        return $sql;
    }

    public function quoteColumn($columnName): string
    {
        $colAlias = explode('.', $columnName);
        if (count($colAlias) === 2) {
            return $this->quoteColumn($colAlias[0]) . '.' . $this->quoteColumn($colAlias[1]);
        }

        switch ($this->driver) {
            case 'mysql':
            case 'mariadb':
                return "`{$columnName}`";
            case 'mssql':
                return "[{$columnName}]";
            case 'pgsql':
                return '"' . $columnName . '"';
            default:
                return $columnName;
        }
    }

    public function quoteTable($table): string
    {
        $table = $this->tablePrefix . $table;

        switch ($this->driver) {
            case 'mysql':
            case 'mariadb':
                return '`' . $table . '`';
            case 'mssql':
                return "[{$table}]";
            case 'pgsql':
                return '"' . $table . '"';
            default:
                return $table;
        }
    }

    // ─── Info ──────────────────────────────────────────────────

    public function info(): array
    {
        $keyNames = [
            'server'     => PDO::ATTR_SERVER_INFO,
            'driver'     => PDO::ATTR_DRIVER_NAME,
            'client'     => PDO::ATTR_CLIENT_VERSION,
            'version'    => PDO::ATTR_SERVER_VERSION,
            'connection' => PDO::ATTR_CONNECTION_STATUS,
        ];

        foreach ($keyNames as $key => $value) {
            try {
                $keyNames[$key] = $this->getAttribute($value);
            } catch (PDOException $e) {
                $keyNames[$key] = $e->getMessage();
            }
        }

        return $keyNames;
    }

    public function setFetchMode($mode): bool
    {
        return $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $mode);
    }

    public function setAutoCommit($value): bool
    {
        return $this->setAttribute(PDO::ATTR_AUTOCOMMIT, $value);
    }

    public function getAutoCommit()
    {
        return $this->getAttribute(PDO::ATTR_AUTOCOMMIT);
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    // ─── Helpers ───────────────────────────────────────────────

    public function buildParams($array, $name): array
    {
        $params = [];
        $values = [];
        $parts  = explode('.', $name);
        $name   = $parts[1] ?? $parts[0];

        for ($i = 0; $i < count($array); $i++) {
            $params[$i]         = ":{$name}{$i}";
            $values[$params[$i]] = $array[$i];
        }

        return [implode(',', $params), $values];
    }

    public function statement($statement, array $params = [])
    {
        $stm = $this->prepare($statement);
        $stm->execute($params);
        $this->rowCount = $stm->rowCount();
        return $stm;
    }

    public function toSnakeCase($name): string
    {
        $name = preg_replace('/([A-Z])/', '_$1', $name);
        return strtolower(trim($name, '_'));
    }

    public function lastId()
    {
        return $this->lastInsertId();
    }

    // ─── Internals ─────────────────────────────────────────────

    private function prepareConditions($conditions, $params, &$inputParams): string
    {
        if (is_array($conditions)) {
            $lines = [];
            $i     = 0;
            foreach ($conditions as $name => $value) {
                if ($value instanceof Expr) {
                    $lines[] = $this->quoteColumn($name) . '=' . $value->raw;
                } else {
                    $bindName  = $this->nextBind() . $i;
                    $lines[]  = $this->quoteColumn($name) . '=:' . $bindName;
                    $inputParams[$bindName] = $value;
                }
                $i++;
            }
            return implode(' AND ', $lines);
        }

        if (is_string($conditions) && is_array($params) && Arr::isAssoc($params)) {
            foreach ($params as $name => $value) {
                if (!($value instanceof Expr)) {
                    $inputParams[$name] = $value;
                }
                // Expr values are already embedded in the conditions string
            }
            return $conditions;
        }

        if (is_string($conditions) && (is_scalar($params) || (is_array($params) && !Arr::isAssoc($params)))) {
            if (is_scalar($params)) {
                $params = [$params];
            }
            $inputParams += $params;
            return $conditions;
        }

        return '';
    }

    /**
     * Generate a unique bind parameter name for this instance.
     */
    protected function nextBind(): string
    {
        return '_b' . (++$this->bindCounter);
    }
}

/**
 * MySQL / MariaDB 驱动封装（内部使用 Pdo\Mysql，PHP < 8.4 时回退普通 PDO）。
 */
class ZPDOMySql extends ZPDO
{
    protected function createConnection(string $dsn, ?string $username, ?string $password, ?array $options): PDO
    {
        if (class_exists(\Pdo\Mysql::class)) {
            return new \Pdo\Mysql($dsn, $username, $password, $options);
        }
        return new PDO($dsn, $username, $password, $options);
    }
}

/**
 * SQLite 驱动封装（内部使用 Pdo\Sqlite，PHP < 8.4 时回退普通 PDO）。
 */
class ZPDOSqlite extends ZPDO
{
    protected function createConnection(string $dsn, ?string $username, ?string $password, ?array $options): PDO
    {
        if (class_exists(\Pdo\Sqlite::class)) {
            return new \Pdo\Sqlite($dsn, $username, $password, $options);
        }
        return new PDO($dsn, $username, $password, $options);
    }
}

/**
 * PostgreSQL 驱动封装（内部使用 Pdo\Pgsql，PHP < 8.4 时回退普通 PDO）。
 */
class ZPDOPgsql extends ZPDO
{
    protected function createConnection(string $dsn, ?string $username, ?string $password, ?array $options): PDO
    {
        if (class_exists(\Pdo\Pgsql::class)) {
            return new \Pdo\Pgsql($dsn, $username, $password, $options);
        }
        return new PDO($dsn, $username, $password, $options);
    }
}
