<?php

namespace zap\db;

use PDO;
use PDOStatement;

class Statement extends PDOStatement
{
    /** @var callable|null 查询日志回调 (sql, params, durationMs)，由 ZPDO::prepare() 注入 */
    protected $queryLogger = null;

    /**
     * 注入查询日志回调（仅在开启 query log 时由 ZPDO::prepare() 调用）。
     */
    public function setQueryLogger(?callable $logger): void
    {
        $this->queryLogger = $logger;
    }

    /**
     * 统一在此处记录实际执行的 SQL、绑定参数与耗时。
     *
     * 所有 Query / Model / CRUD 都走 prepare() + execute()，
     * 过去只有 exec()/query()/insert() 会记日志，导致 SQL 面板几乎为空。
     * 未开启日志时直接透传，零额外开销。
     */
    public function execute($params = null): bool
    {
        if ($this->queryLogger === null) {
            return parent::execute($params);
        }

        $start = microtime(true);
        try {
            return parent::execute($params);
        } finally {
            // 即使执行失败也记录，便于定位出错的语句
            $logger = $this->queryLogger;
            $logger(
                (string)$this->queryString,
                is_array($params) ? $params : [],
                (microtime(true) - $start) * 1000
            );
        }
    }

    /**
     * Bind a parameter to an IN clause placeholder.
     *
     * @param string $placeholder Placeholder name
     * @param array $values Array of values
     * @return int
     */
    public function bindIn(string $placeholder, array $values): int
    {
        $params = [];
        for ($i = 0; $i < count($values); $i++) {
            $params[$i] = ":{$placeholder}{$i}";
            $this->bindValue($params[$i], $values[$i]);
        }

        return 0;
    }

    /**
     * Execute with IN parameter binding.
     *
     * @param string $sql SQL with named placeholders for IN
     * @param array $inputParams Parameters including array values for IN
     */
    public function executeWithIn(string $sql, array $inputParams = []): bool
    {
        $params = [];

        foreach ($inputParams as $name => $value) {
            if (is_array($value)) {
                $inParams = [];
                for ($i = 0; $i < count($value); $i++) {
                    $key = ":{$name}{$i}";
                    $inParams[] = $key;
                    $params[$key] = $value[$i];
                }
                $sql = str_replace(':' . $name, implode(',', $inParams), $sql);
            } else {
                $params[':' . $name] = $value;
            }
        }

        foreach ($params as $key => $val) {
            $this->bindValue($key, $val);
        }

        return true;
    }

    /**
     * Fetch all rows as an associative array.
     */
    public function fetchAllAssoc(): array
    {
        return $this->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch all rows as an array of objects.
     */
    public function fetchAllObj(): array
    {
        return $this->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Fetch all rows as a numbered array.
     */
    public function fetchAllNum(): array
    {
        return $this->fetchAll(PDO::FETCH_NUM);
    }

    /**
     * Fetch all values of a single column.
     */
    public function fetchColumnAll(int $column = 0): array
    {
        return $this->fetchAll(PDO::FETCH_COLUMN, $column);
    }

    /**
     * Fetch key-value pairs.
     */
    public function fetchPairs(): array
    {
        return $this->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Fetch grouped results.
     */
    public function fetchGrouped(int $style = PDO::FETCH_OBJ): array
    {
        return $this->fetchAll(PDO::FETCH_GROUP | $style);
    }

    /**
     * Get column meta information.
     */
    public function getColumnMeta(int $column): array|false
    {
        return parent::getColumnMeta($column);
    }
}
