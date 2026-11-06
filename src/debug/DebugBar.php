<?php

declare(strict_types=1);

namespace zap\debug;

use Throwable;
use zap\App;
use zap\DB;

/**
 * 浮动调试条（Debug Bar）
 *
 * 目标：Debug 模式下把「不致命但会打断页面」的 PHP 警告/通知、SQL 查询、
 * 耗时/内存、请求与配置等信息集中到一个页面底部浮动的条里，
 * 而不是像传统 display_errors 那样直接输出、撑坏布局或替换整页。
 *
 * 工作机制：
 *  1. App::run() 在 debug + Web 环境下调用 boot()，开启一个带回调的输出缓冲，
 *     并打开 DB 查询日志；
 *  2. ErrorHandler 遇到非致命错误时调用 addError() 收集，页面继续正常渲染；
 *  3. 脚本结束时（析构函数、shutdown 函数之后）PHP 冲刷输出缓冲并调用 inject()，
 *     在最后一个 </body> 前插入调试条 HTML（纯字符串拼接，不在回调内使用输出缓冲）。
 *
 * 设计约束：
 *  - position:fixed + 自定义标签 + 作用域 CSS，完全脱离文档流，不改变页面布局；
 *  - 只注入「含 </body> 且 Content-Type 为 HTML」的响应，不污染 JSON / 下载 / 片段；
 *  - 调试条自身任何异常都不得影响正常页面输出。
 */
final class DebugBar
{
    /** 单例 */
    private static ?DebugBar $instance = null;

    /** 请求起始时间（优先使用 Web 服务器提供的真实起始时间） */
    private float $startedAt;

    /** @var array<string, array{level:string,type:string,message:string,file:string,line:int,at:float,count:int}> 去重后的消息 */
    private array $messages = [];

    /** 最多保留的不同消息数，避免循环内刷屏 */
    private int $messageLimit = 200;

    private bool $booted = false;

    private bool $injected = false;

    /** 警告级别（红色） */
    private const WARNING_LEVELS = [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING];

    /** 提示 / 弃用级别（黄色） */
    private const NOTICE_LEVELS = [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, 2048];

    private function __construct()
    {
        $this->startedAt = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    public static function instance(): self
    {
        return static::$instance ??= new self();
    }

    /**
     * 是否启用（与 ErrorHandler::isDebug() 使用同一判据）
     */
    public static function enabled(): bool
    {
        return \config('config.debug', false) === true;
    }

    /**
     * 由 App::run() 调用：仅 Debug + Web 请求才挂输出缓冲，生产环境零开销。
     */
    public static function boot(): void
    {
        $bar = static::instance();

        if ($bar->booted || !static::enabled() || self::isCli()) {
            return;
        }

        $bar->booted = true;

        try {
            DB::enableQueryLog();
        } catch (Throwable $e) {
            // 数据库不可用时不影响页面渲染，SQL 面板显示为空即可
        }

        // 回调在脚本结束时（所有输出产生之后）执行，见类注释
        ob_start([$bar, 'inject']);
    }

    /**
     * 收集一条 PHP 错误 / 警告 / 通知。
     */
    public function addError(int $errno, string $type, string $message, string $file, int $line): void
    {
        $level = 'error';
        if (in_array($errno, self::WARNING_LEVELS, true)) {
            $level = 'warning';
        } elseif (in_array($errno, self::NOTICE_LEVELS, true)) {
            $level = 'notice';
        }

        $this->push($level, $type, $message, $file, $line);
    }

    /**
     * 供业务代码主动打点：\zap\debug\DebugBar::log('xxx', '标签')
     */
    public static function log(string $message, string $label = 'Log'): void
    {
        static::instance()->push('log', $label, $message, '', 0);
    }

    /**
     * 已收集的消息总条数（含重复次数）。
     */
    public function messageCount(): int
    {
        $total = 0;
        foreach ($this->messages as $item) {
            $total += $item['count'];
        }
        return $total;
    }

    /**
     * 输出缓冲回调：在最终 HTML 的 </body> 前插入调试条。
     */
    public function inject(string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
    {
        if ($this->injected) {
            return $buffer;
        }

        try {
            if (!static::enabled() || self::isNonHtmlResponse($buffer)) {
                return $buffer;
            }

            $pos = strripos($buffer, '</body>');
            if ($pos === false) {
                return $buffer;
            }

            $this->injected = true;
            $html = $this->render();
            if ($html === '') {
                return $buffer;
            }

            return substr($buffer, 0, $pos) . "\n" . $html . "\n" . substr($buffer, $pos);
        } catch (Throwable $e) {
            // 调试条自身的任何异常都不得破坏正常页面
            return $buffer;
        }
    }

    // ───────────────────── 内部实现 ─────────────────────

    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * 排除 JSON / 附件 / 纯文本 / 图片等响应，只在完整 HTML 页面注入。
     */
    private static function isNonHtmlResponse(string $buffer): bool
    {
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = strtolower(trim(substr($header, 13)));
            } elseif (stripos($header, 'Content-Disposition:') === 0
                && stripos($header, 'attachment') !== false) {
                return true;
            }
        }

        if ($contentType !== '' && !str_contains($contentType, 'html')) {
            return true;
        }

        $trimmed = ltrim($buffer);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return true; // JSON 片段
        }

        return false;
    }

    private function push(
        string $level,
        string $type,
        string $message,
        string $file,
        int $line
    ): void {
        $key = md5($level . '|' . $type . '|' . $message . '|' . $file . '|' . $line);

        // 同一个位置的重复警告只累加次数，避免循环内刷屏
        if (isset($this->messages[$key])) {
            $this->messages[$key]['count']++;
            return;
        }

        if (count($this->messages) >= $this->messageLimit) {
            return;
        }

        $this->messages[$key] = [
            'level'   => $level,
            'type'    => $type,
            'message' => $message,
            'file'    => $file,
            'line'    => $line,
            'at'      => (microtime(true) - $this->startedAt) * 1000,
            'count'   => 1,
        ];
    }

    /**
     * 汇总本次请求的所有调试数据。
     */
    private function collect(): array
    {
        $queries = [];
        try {
            $queries = DB::getQueryLog();
        } catch (Throwable $e) {
            // 忽略
        }

        $sqlTime = 0.0;
        foreach ((array)$queries as $q) {
            if (is_array($q) && isset($q['duration']) && is_numeric($q['duration'])) {
                $sqlTime += (float)$q['duration'];
            }
        }

        $messages = array_values($this->messages);
        $warnings = 0;
        foreach ($messages as $item) {
            if ($item['level'] === 'warning' || $item['level'] === 'error') {
                $warnings += $item['count'];
            }
        }

        return [
            'time'       => (microtime(true) - $this->startedAt) * 1000,
            // 注意：不能用 memory_get_*(true)（real usage）——它按分配器大块
            // 量化（典型 2MB 粒度），小请求永远显示恒定的 2.0 MB。
            // false（emalloc 统计）才是字节级精确值。
            'memory'     => memory_get_usage(false) / 1048576,
            'peak'       => memory_get_peak_usage(false) / 1048576,
            'status'     => http_response_code() ?: 200,
            'method'     => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'uri'        => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'php'        => PHP_VERSION,
            'framework'  => App::version(),
            'messageTotal' => $this->messageCount(),
            'warningTotal' => $warnings,
            'messages'   => $messages,
            'queries'    => is_array($queries) ? $queries : [],
            'sqlTime'    => $sqlTime,
            'request'    => $this->collectRequest(),
            'config'     => $this->collectConfig(),
            'files'      => get_included_files(),
        ];
    }

    /**
     * 请求数据（敏感字段自动打码）。
     */
    private function collectRequest(): array
    {
        $serverKeys = [
            'HTTP_HOST', 'REQUEST_URI', 'REQUEST_METHOD', 'QUERY_STRING',
            'HTTP_REFERER', 'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'CONTENT_TYPE',
            'HTTP_X_REQUESTED_WITH', 'REMOTE_ADDR', 'SERVER_SOFTWARE',
            'HTTPS', 'REQUEST_TIME_FLOAT',
        ];

        $server = [];
        foreach ($serverKeys as $key) {
            if (isset($_SERVER[$key])) {
                $server[$key] = $_SERVER[$key];
            }
        }

        return [
            'GET'    => is_array($_GET) ? $_GET : [],
            'POST'   => is_array($_POST) ? $_POST : [],
            'COOKIE' => is_array($_COOKIE) ? $_COOKIE : [],
            'SERVER' => $server,
        ];
    }

    private function collectConfig(): array
    {
        return [
            'config.debug'        => \config('config.debug', false) ? 'true' : 'false',
            'config.log'          => \config('config.log', false) ? 'true' : 'false',
            'config.theme'        => (string)\config('config.theme', ''),
            'admin.prefix'        => (string)\config('admin.prefix', ''),
            'Framework'           => App::version(),
            'PHP Version'         => PHP_VERSION,
            'PHP SAPI'            => PHP_SAPI,
            'error_reporting'     => (string)error_reporting(),
            'display_errors'      => (string)ini_get('display_errors'),
            'memory_limit'        => (string)ini_get('memory_limit'),
            'max_execution_time'  => (string)ini_get('max_execution_time'),
            'post_max_size'       => (string)ini_get('post_max_size'),
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'opcache.enable'      => (string)ini_get('opcache.enable'),
            'Loaded extensions'   => (string)count(get_loaded_extensions()),
            'Server time'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 渲染：读静态模板 + 字符串替换，绝不使用输出缓冲（回调内禁止 ob_start）。
     */
    private function render(): string
    {
        $d = $this->collect();

        $tplFile = __DIR__ . '/resources/bar.html';
        if (!is_file($tplFile)) {
            return '';
        }
        $tpl = (string)@file_get_contents($tplFile);

        return str_replace(
            ['{{CHIPS}}', '{{TABS}}', '{{SECTIONS}}'],
            [
                $this->buildChips($d),
                $this->buildTabs($d),
                $this->buildSections($d),
            ],
            $tpl
        );
    }

    private static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 敏感字段打码、超长值截断。
     */
    private static function safeValue(string $key, $value, bool $forceMask = false): string
    {
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return 'object(' . get_class($value) . ')';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        $text = (string)$value;
        // SQL 里出现 password/token 等列时，即使绑定参数名只是 :p 也一并打码
        if ($forceMask || preg_match('/pass|pwd|token|secret|key|csrf|auth|sign|session|cookie/i', $key)) {
            return $text === '' ? '(empty)' : '•••••• (' . strlen($text) . ' chars)';
        }
        if (strlen($text) > 500) {
            $text = substr($text, 0, 500) . '…';
        }
        return $text;
    }

    private static function row(string $key, string $value): string
    {
        return '<zap-dbg-row><zap-dbg-k>' . self::e($key) . '</zap-dbg-k>'
            . '<zap-dbg-v>' . self::e($value) . '</zap-dbg-v></zap-dbg-row>';
    }

    private static function groupTitle(string $title): string
    {
        return '<zap-dbg-title>' . self::e($title) . '</zap-dbg-title>';
    }

    private function buildChips(array $d): string
    {
        $h = '<zap-dbg-chip>' . number_format($d['time'], 1) . ' ms</zap-dbg-chip>';
        $h .= '<zap-dbg-chip>' . number_format($d['memory'], 1) . ' MB'
            . ' <zap-dbg-dim>(peak ' . number_format($d['peak'], 1) . ')</zap-dbg-dim></zap-dbg-chip>';
        $h .= '<zap-dbg-chip>SQL ' . count($d['queries'])
            . ' <zap-dbg-dim>/ ' . number_format($d['sqlTime'], 1) . ' ms</zap-dbg-dim></zap-dbg-chip>';

        $warnClass = $d['warningTotal'] > 0 ? ' class="is-alert"' : '';
        $h .= '<zap-dbg-chip' . $warnClass . '>&#9888; ' . $d['warningTotal'] . ' warn</zap-dbg-chip>';
        $h .= '<zap-dbg-chip>HTTP ' . self::e($d['status']) . '</zap-dbg-chip>';
        $h .= '<zap-dbg-chip class="is-path">' . self::e($d['method'] . ' ' . $d['uri']) . '</zap-dbg-chip>';
        $h .= '<zap-dbg-chip class="is-path"><zap-dbg-dim>PHP ' . self::e($d['php']) . '</zap-dbg-dim></zap-dbg-chip>';

        return $h;
    }

    private function buildTabs(array $d): string
    {
        $tabs = [
            ['request', '请求'],
            ['messages', '消息 (' . $d['messageTotal'] . ')'],
            ['sql', 'SQL (' . count($d['queries']) . ')'],
            ['config', '配置'],
            ['files', '文件 (' . count($d['files']) . ')'],
        ];

        $h = '';
        foreach ($tabs as [$name, $label]) {
            $h .= '<zap-dbg-tab data-target="' . self::e($name) . '">' . self::e($label) . '</zap-dbg-tab>';
        }
        return $h;
    }

    private function buildSections(array $d): string
    {
        $h = '';

        // ── 请求 ──
        $body = self::groupTitle('基本信息');
        $body .= self::row('Method / URI', $d['method'] . ' ' . $d['uri']);
        $body .= self::row('Status', (string)$d['status']);
        $body .= self::row('Time', number_format($d['time'], 2) . ' ms');
        $body .= self::row('Memory / Peak', number_format($d['memory'], 2) . ' / ' . number_format($d['peak'], 2) . ' MB');
        $body .= self::row('Framework / PHP', $d['framework'] . ' / ' . $d['php']);

        foreach (['GET', 'POST', 'COOKIE', 'SERVER'] as $group) {
            $body .= self::groupTitle($group);
            $values = $d['request'][$group] ?? [];
            if (!is_array($values) || $values === []) {
                $body .= self::row('—', '(empty)');
                continue;
            }
            foreach ($values as $key => $value) {
                $body .= self::row((string)$key, self::safeValue((string)$key, $value));
            }
        }
        $h .= '<zap-dbg-section data-name="request">' . $body . '</zap-dbg-section>';

        // ── 消息（PHP 警告 / 通知 / 自定义打点）──
        $body = self::groupTitle('PHP 消息 / Messages');
        if ($d['messages'] === []) {
            $body .= '<zap-dbg-empty>本次请求没有收集到 PHP 警告或通知。</zap-dbg-empty>';
        } else {
            foreach ($d['messages'] as $item) {
                $level = self::e($item['level']);
                $body .= '<zap-dbg-msg class="lv-' . $level . '">';
                $body .= '<zap-dbg-msg-head>' . self::e($item['type'])
                    . ($item['count'] > 1 ? ' <zap-dbg-badge>&times;' . $item['count'] . '</zap-dbg-badge>' : '')
                    . ' <zap-dbg-dim>@ ' . number_format($item['at'], 1) . ' ms</zap-dbg-dim></zap-dbg-msg-head>';
                $body .= '<zap-dbg-msg-body>' . self::e($item['message']) . '</zap-dbg-msg-body>';
                if ($item['file'] !== '') {
                    $body .= '<zap-dbg-msg-file>' . self::e($item['file'] . ':' . $item['line']) . '</zap-dbg-msg-file>';
                }
                $body .= '</zap-dbg-msg>';
            }
        }
        $h .= '<zap-dbg-section data-name="messages">' . $body . '</zap-dbg-section>';

        // ── SQL ──
        $body = self::groupTitle('数据库查询 / Query Log (' . count($d['queries']) . ')');
        if ($d['queries'] === []) {
            $body .= '<zap-dbg-empty>本次请求没有记录到查询。</zap-dbg-empty>';
        } else {
            foreach ($d['queries'] as $i => $q) {
                if (!is_array($q)) {
                    continue;
                }
                $sql = (string)($q['sql'] ?? '');
                $params = $q['params'] ?? [];
                $sensitiveSql = preg_match('/pass(word|wd)?|pwd|token|secret|api_?key|_hash|csrf|auth/i', $sql) === 1;
                $paramText = '';
                if (is_array($params) && $params !== []) {
                    $parts = [];
                    foreach ($params as $k => $v) {
                        $parts[] = self::e((string)$k) . '=' . self::e(self::safeValue((string)$k, $v, $sensitiveSql));
                    }
                    $paramText = implode(', ', $parts);
                }
                $meta = [];
                if (isset($q['duration']) && is_numeric($q['duration'])) {
                    $meta[] = number_format((float)$q['duration'], 2) . ' ms';
                }
                if (isset($q['time'])) {
                    $meta[] = '@ ' . number_format(((float)$q['time'] - $this->startedAt) * 1000, 1) . ' ms';
                }
                $body .= '<zap-dbg-sql><zap-dbg-sql-n>#' . ($i + 1) . '</zap-dbg-sql-n>'
                    . '<zap-dbg-sql-body><code>' . self::e($sql) . '</code>'
                    . ($paramText !== '' ? '<zap-dbg-sql-params>' . $paramText . '</zap-dbg-sql-params>' : '')
                    . '</zap-dbg-sql-body><zap-dbg-dim>' . self::e(implode(' · ', $meta)) . '</zap-dbg-dim></zap-dbg-sql>';
            }
        }
        $h .= '<zap-dbg-section data-name="sql">' . $body . '</zap-dbg-section>';

        // ── 配置 ──
        $body = self::groupTitle('运行时配置 / Environment');
        foreach ($d['config'] as $key => $value) {
            $body .= self::row((string)$key, (string)$value);
        }
        $h .= '<zap-dbg-section data-name="config">' . $body . '</zap-dbg-section>';

        // ── 加载的文件 ──
        $files = $d['files'];
        $total = count($files);
        $shown = array_slice($files, 0, 200);
        $body = self::groupTitle('已加载文件 / Included files (' . $total . ')');
        foreach ($shown as $file) {
            $body .= self::row('', (string)$file);
        }
        if ($total > count($shown)) {
            $body .= '<zap-dbg-empty>… 另有 ' . ($total - count($shown)) . ' 个文件未显示</zap-dbg-empty>';
        }
        $h .= '<zap-dbg-section data-name="files">' . $body . '</zap-dbg-section>';

        return $h;
    }
}
