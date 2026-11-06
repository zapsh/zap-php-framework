<?php

namespace zap\net;

use zap\exception\CurlException;

class Requests
{
    const GET     = 'GET';
    const POST    = 'POST';
    const PUT     = 'PUT';
    const PATCH   = 'PATCH';
    const DELETE  = 'DELETE';
    const HEAD    = 'HEAD';
    const OPTIONS = 'OPTIONS';
    const TRACE   = 'TRACE';
    const VERSION = '1.0.0';

    protected static string $caCert = ZAP_SRC . '/resources/certificates/cacert.pem';
    protected static string $userAgent = 'Zap-PHP/' . self::VERSION;
    protected static int $defaultTimeout = 30;
    protected static int $defaultConnectTimeout = 10;
    protected static bool $defaultFollowRedirects = true;
    protected static int $maxRedirects = 5;
    /** 默认是否校验 SSL 证书（内置 cacert.pem，默认可信） */
    protected static bool $defaultSslVerify = true;

    private function __construct() {}

    // ===================== 配置 =====================

    public static function setUserAgent(string $ua): void
    {
        self::$userAgent = $ua;
    }

    public static function setCaCert(string $path): void
    {
        self::$caCert = $path;
    }

    public static function getCaCert(): string
    {
        return self::$caCert;
    }

    public static function setDefaultTimeout(int $seconds): void
    {
        self::$defaultTimeout = $seconds;
    }

    public static function setDefaultConnectTimeout(int $seconds): void
    {
        self::$defaultConnectTimeout = $seconds;
    }

    /**
     * 配置默认 SSL 证书校验开关
     *
     * 设为 false 可跳过证书校验（同时关闭 VERIFYPEER 与 VERIFYHOST），
     * 仅在访问自签名/内网证书等特殊场景使用。
     */
    public static function setDefaultSslVerify(bool $verify): void
    {
        self::$defaultSslVerify = $verify;
    }

    // ===================== HTTP 方法 =====================

    public static function get(string $url, array|string $params = [], array $headers = [], array $options = []): Response
    {
        return self::request(self::GET, $url, $params, $headers, $options);
    }

    public static function post(string $url, array|string $data = [], array $headers = [], array $options = []): Response
    {
        return self::request(self::POST, $url, $data, $headers, $options);
    }

    public static function put(string $url, array|string $data = [], array $headers = [], array $options = []): Response
    {
        return self::request(self::PUT, $url, $data, $headers, $options);
    }

    public static function patch(string $url, array|string $data = [], array $headers = [], array $options = []): Response
    {
        return self::request(self::PATCH, $url, $data, $headers, $options);
    }

    public static function delete(string $url, array|string $params = [], array $headers = [], array $options = []): Response
    {
        return self::request(self::DELETE, $url, $params, $headers, $options);
    }

    public static function head(string $url, array $headers = [], array $options = []): Response
    {
        return self::request(self::HEAD, $url, [], $headers, $options);
    }

    public static function options(string $url, array|string $data = [], array $headers = [], array $options = []): Response
    {
        return self::request(self::OPTIONS, $url, $data, $headers, $options);
    }

    // ===================== 快捷方法 =====================

    /**
     * 发送 JSON 请求
     */
    public static function json(string $method, string $url, array|string $data = [], array $headers = [], array $options = []): Response
    {
        $headers = array_merge($headers, ['Content-Type: application/json']);
        $body = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        return self::request(strtoupper($method), $url, $body, $headers, $options);
    }

    /**
     * 快捷 GET JSON（返回解析后的数组/对象）
     */
    public static function getJson(string $url, array|string $params = [], array $headers = [], array $options = []): mixed
    {
        $response = self::get($url, $params, $headers, $options);
        return $response->json();
    }

    /**
     * 快捷 POST JSON
     */
    public static function postJson(string $url, array|string $data = [], array $headers = [], array $options = []): Response
    {
        return self::json(self::POST, $url, $data, $headers, $options);
    }

    /**
     * 发送表单上传请求
     */
    public static function multipart(string $url, array $fields = [], array $files = [], array $headers = [], array $options = []): Response
    {
        $postFields = [];
        foreach ($fields as $key => $value) {
            $postFields[$key] = $value;
        }
        foreach ($files as $key => $path) {
            $postFields[$key] = new \CURLFile($path);
        }
        $headers = array_diff_key($headers, array_flip(preg_grep('/^Content-Type:/i', $headers)));
        return self::request(self::POST, $url, $postFields, $headers, $options);
    }

    // ===================== 核心请求方法 =====================

    public static function request(string $method, string $url, array|string $params = [], array $headers = [], array $options = []): Response
    {
        $ch = self::buildHandle($method, $url, $params, $headers, $options);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $info  = curl_getinfo($ch);

        if ($error) {
            throw new CurlException('cURL Error: ' . $error, $errno);
        }

        return new Response($body, $info);
    }

    // ===================== 并发请求 =====================

    /**
     * 并发发送多个请求
     *
     * @param array $requests  [['method' => 'GET', 'url' => '...', 'params' => [], 'headers' => []], ...]
     * @return array<int, Response|CurlException>
     * @throws CurlException
     */
    public static function multi(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $i => $req) {
            $method = strtoupper($req['method'] ?? 'GET');
            $url    = $req['url'];
            $params = $req['params'] ?? [];
            $headers = $req['headers'] ?? [];
            $options = $req['options'] ?? [];

            // 用单次请求的配置构建 curl handle
            $ch = self::buildHandle($method, $url, $params, $headers, $options);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$i] = $ch;
        }

        // 执行所有请求
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle);
            }
        } while ($running && $status === CURLM_OK);

        // 收集结果
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $info  = curl_getinfo($ch);

            curl_multi_remove_handle($multiHandle, $ch);

            if ($error) {
                $results[$i] = new CurlException('cURL Error: ' . $error, $errno);
            } else {
                $results[$i] = new Response($body, $info);
            }
        }

        return $results;
    }

    /**
     * 构建 curl handle（request / multi 共用，统一 SSL、超时、重定向等配置）
     *
     * @return \CurlHandle
     */
    protected static function buildHandle(string $method, string $url, array|string $params = [], array $headers = [], array $options = []): \CurlHandle
    {
        $method = strtoupper($method);
        $options = array_merge([
            'timeout'          => self::$defaultTimeout,
            'connect_timeout'  => self::$defaultConnectTimeout,
            'ssl_verify'       => self::$defaultSslVerify,
            'return_header'    => false,
            'follow_redirects' => self::$defaultFollowRedirects,
            'max_redirects'    => self::$maxRedirects,
            'cookie'           => null,
            'cookie_file'      => null,
            'auth'             => null,
            'referer'          => null,
        ], $options);

        $ch = curl_init();

        if (in_array($method, ['GET', 'DELETE', 'HEAD']) && !empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, $options['return_header']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, self::$userAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout']);

        // SSL：默认校验证书（内置 cacert.pem）；ssl_verify=false 时跳过校验（同时关闭 VERIFYPEER 与 VERIFYHOST）
        $verify = (bool)$options['ssl_verify'];
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        if ($verify && is_file(self::$caCert)) {
            curl_setopt($ch, CURLOPT_CAINFO, self::$caCert);
        }

        // 重定向
        if ($options['follow_redirects']) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, $options['max_redirects']);
        }

        // Cookie
        if ($options['cookie']) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
        }
        if ($options['cookie_file']) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $options['cookie_file']);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $options['cookie_file']);
        }

        // HTTP 基本认证
        if ($options['auth'] && is_array($options['auth']) && count($options['auth']) === 2) {
            curl_setopt($ch, CURLOPT_USERPWD, $options['auth'][0] . ':' . $options['auth'][1]);
        }

        // Referer
        if ($options['referer']) {
            curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
        }

        // 根据方法设置请求体和自定义方法
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'OPTIONS'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($params) ? $params : http_build_query($params));
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
        } elseif (!in_array($method, ['GET', 'HEAD'])) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        return $ch;
    }
}
