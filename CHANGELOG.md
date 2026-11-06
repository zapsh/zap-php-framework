# Changelog

所有重要的变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### 变更

- **最低 PHP 版本提升至 8.2**：`composer.json` 的 `php` 约束由 `>=7.4.0` 改为 `>=8.2.0`，PHPUnit 开发依赖升级到 `^11.5`
- 移除 `src/compat.php` 兼容层（`Stringable` / `str_contains()` / `str_starts_with()` / `str_ends_with()` polyfill），并从 `autoload.files` 中删除
- `zap\App`、`zap\db\Model`、`zap\util\ZArray` 的 `ArrayAccess` / `JsonSerializable` 方法补齐 PHP 8 原生签名（`mixed` 参数与返回类型），移除 `#[\ReturnTypeWillChange]`
- `zap\db\Statement::getColumnMeta()` 对齐 `PDOStatement` 原生签名：`getColumnMeta(int $column): array|false`
- 移除 `ZArray::serialize()` / `ZArray::unserialize()` 旧 `Serializable` 风格方法，仅保留 `__serialize()` / `__unserialize()`
- 移除 mbstring 扩展缺失时的回退分支（`Str::len()` / `Str::substr()` / `Dispatcher::parseUrlPath()` / `sanitize()`），`ext-mbstring` 已是硬依赖

### 修复（PHP 8.4 / 8.5 兼容）

- `zap\ErrorHandler`：不再引用 PHP 8.4 起弃用的 `E_STRICT` 常量，错误等级映射改用字面量 `2048`
- `zap\ErrorHandler`：`http_response_code()` 与 `header()` 输出前增加 `headers_sent()` 判断，修复 "Cannot set response code - headers already sent" 警告（新增 `sendStatusCode()` 内部方法）
- `zap\ErrorHandler`：`E_NOTICE` / `E_USER_NOTICE` / `E_DEPRECATED` / `E_USER_DEPRECATED` 改为仅记录日志，不再渲染整页错误，避免第三方库的弃用提示打断正常页面输出
- `zap\ErrorHandler`：修正 `logError()` / `logWarning()` 的日志类引用（原 `zap\log\Log` 不存在，导致错误日志被丢弃），改为 `zap\Log`
- `zap\image\Image`：移除 PHP 8.5 起弃用的 `imagedestroy()`（PHP 8 起 GD 资源由 GC 回收），`destroy()` / `__destruct()` 改为释放引用
- `zap\net\Requests`：移除 PHP 8.5 起弃用的 `curl_close()` / `curl_multi_close()`
- `zap\fileupload\UploadedFile`、`zap\util\FileUtils`：移除 PHP 8.5 起弃用的 `finfo_close()`
- 隐式可空参数改为显式声明（PHP 8.4 起弃用）：`Query::whereColumn()` / `orWhereColumn()` / `pluck()` / `update()`、`Pagination::buildLink()`、`ViewNotFoundException` / `NotFoundException` / `CurlException` 构造参数

### 注意

- 升级后需重新生成自动加载：`composer update zapsh/zap-php-framework`（或 `composer dump-autoload`），否则 `autoload_files.php` 仍会尝试加载已删除的 `src/compat.php`



## 说明

历史提交已合并为单个提交，早期增量版本（1.0.1 ~ 1.0.6）的详细变更不再逐一列出。
