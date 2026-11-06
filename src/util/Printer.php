<?php

namespace zap\util;

use Throwable;

/**
 * 异常调试打印器
 *
 * 在 debug 模式下将异常信息格式化为 HTML 输出，便于开发调试。
 * 用法： (new Printer($e))->display();
 */
class Printer
{
    /** @var Throwable */
    protected $exception;

    /** @var bool 是否输出完整调用栈 */
    protected $showTrace = true;

    public function __construct($exception)
    {
        $this->exception = $exception;
    }

    /**
     * 输出格式化后的异常信息
     */
    public function display(): void
    {
        $e = $this->exception;
        $type = $e instanceof Throwable ? get_class($e) : gettype($e);
        $message = $e instanceof Throwable ? $e->getMessage() : (string)$e;
        $file = $e instanceof Throwable ? $e->getFile() : '';
        $line = $e instanceof Throwable ? $e->getLine() : 0;

        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>异常 - ' . htmlspecialchars($type) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f7fa; color: #333; padding: 20px;
        }
        .exception-wrap { max-width: 960px; margin: 0 auto; }
        .exception-header {
            background: #fff; border: 1px solid #e1e4e8; border-left: 4px solid #d73a49; border-radius: 8px;
            padding: 16px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .exception-header h3 { font-size: 18px; color: #d73a49; word-break: break-all; }
        .exception-header .message { font-size: 14px; color: #a00; margin-top: 8px; word-break: break-all; }
        .exception-header .meta { font-size: 13px; color: #666; margin-top: 10px; }
        .exception-header .meta span { margin-right: 16px; }
        .label {
            display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;
        }
        .label-danger { background: #ffdce0; color: #86181d; }
        .trace {
            background: #fff; border: 1px solid #e1e4e8; border-radius: 8px;
            padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .trace h4 { font-size: 15px; margin-bottom: 12px; color: #24292e; }
        .trace ol { list-style: none; counter-reset: item; }
        .trace li {
            counter-increment: item; position: relative; padding: 8px 0 8px 32px;
            border-bottom: 1px dashed #eee; font-size: 13px; word-break: break-all;
        }
        .trace li:last-child { border-bottom: none; }
        .trace li::before {
            content: "#" counter(item); position: absolute; left: 0; top: 8px;
            font-size: 12px; color: #999; font-weight: 600;
        }
        .trace .fn { color: #0366d6; }
        .trace .file { color: #999; font-size: 12px; margin-top: 2px; }
    </style>
</head>
<body>
    <div class="exception-wrap">
        <div class="exception-header">
            <h3><span class="label label-danger">' . htmlspecialchars($type) . '</span></h3>
            <p class="message">' . htmlspecialchars($message) . '</p>
            <div class="meta">
                <span>文件: ' . htmlspecialchars($file) . '</span>
                <span>行: ' . $line . '</span>';
        if ($e instanceof Throwable) {
            $prev = $e->getPrevious();
            if ($prev) {
                $html .= '<span>上一个异常: ' . htmlspecialchars(get_class($prev)) . ': ' . htmlspecialchars($prev->getMessage()) . '</span>';
            }
        }
        $html .= '</div></div>';

        if ($this->showTrace && $e instanceof Throwable) {
            $html .= '<div class="trace"><h4>调用栈</h4><ol>';
            foreach ($e->getTrace() as $item) {
                $fn = ($item['class'] ?? '') . ($item['type'] ?? '') . ($item['function'] ?? '') . '()';
                $file = $item['file'] ?? '';
                $lineNo = $item['line'] ?? '';
                $html .= '<li><div class="fn">' . htmlspecialchars($fn) . '</div>'
                       . '<div class="file">' . htmlspecialchars($file) . ' : ' . $lineNo . '</div></li>';
            }
            $html .= '</ol></div>';
        }

        $html .= '</div></body></html>';

        echo $html;
    }
}
