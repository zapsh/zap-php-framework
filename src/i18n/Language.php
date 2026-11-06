<?php

namespace zap\i18n;

use zap\traits\SingletonTrait;
use zap\util\Str;

class Language
{
    use SingletonTrait;

    /** @var array 已加载的语言消息 */
    public $messages = [];

    /** @var string 当前语言（统一使用 zh-CN 这类连字符形式） */
    public $language = 'zh-CN';

    /** @var string 回退语言 */
    public $fallbackLanguage = 'en';

    /** @var string[] 语言文件搜索路径 */
    public $languagePath = [];

    /** @var array 已加载的语言文件标记，键为 "语言::文件名" */
    protected $loaded = [];

    public function __construct()
    {
        $this->languagePath = [ZAP_SRC . '/resources/languages'];
    }

    // ===================== 配置方法 =====================

    /**
     * 规范化语言标识
     *
     * 把各种写法统一成 BCP-47 的连字符形式，避免 zh_CN / zh-CN / ZH-cn 混用
     * 导致语言目录查找失败。
     *
     * @param string $language
     * @return string 如 zh-CN、en、zh-Hans-TW
     */
    public static function normalizeLocale(string $language): string
    {
        $language = trim(str_replace('_', '-', $language), "- \t\n\r\0\x0B");
        if ($language === '') {
            return '';
        }

        $parts = array_values(array_filter(explode('-', $language), 'strlen'));
        foreach ($parts as $i => $part) {
            if ($i === 0) {
                // 主语言 subtag：小写
                $parts[$i] = strtolower($part);
                continue;
            }
            $length = strlen($part);
            if ($length === 4) {
                // 文字 subtag：首字母大写，如 Hans / Latn
                $parts[$i] = ucfirst(strtolower($part));
            } elseif ($length === 2) {
                // 地区 subtag：大写，如 CN / TW
                $parts[$i] = strtoupper($part);
            } else {
                $parts[$i] = strtolower($part);
            }
        }

        return implode('-', $parts);
    }

    /**
     * 设置 / 获取当前语言
     *
     * @param string|null $language
     * @return static|string
     */
    public static function locale($language = null)
    {
        if ($language === null) {
            return static::instance()->language;
        }
        static::instance()->language = static::normalizeLocale((string) $language);
        return static::instance();
    }

    /**
     * 设置回退语言
     *
     * @param string $language
     * @return static
     */
    public static function fallback($language)
    {
        static::instance()->fallbackLanguage = static::normalizeLocale((string) $language);
        return static::instance();
    }

    /**
     * 切换语言
     *
     * @deprecated 使用 Language::locale($language)
     * @param string $language
     */
    public static function useLanguage($language = 'zh-CN')
    {
        static::locale($language);
        if (function_exists('resource_path')) {
            static::addPath(resource_path('languages'));
        }
    }

    /**
     * 获取可用语言列表
     *
     * @return array
     */
    public static function availableLocales()
    {
        $locales = [];
        foreach (static::instance()->languagePath as $path) {
            if (!is_dir($path)) {
                continue;
            }
            foreach (scandir($path) as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir($path . '/' . $dir)) {
                    continue;
                }
                $locale = static::normalizeLocale($dir);
                $locales[$locale] = $locale;
            }
        }
        return array_values($locales);
    }

    // ===================== 路径管理 =====================

    /**
     * 添加语言文件搜索路径
     *
     * @param string $path
     */
    public static function addPath($path)
    {
        $path = rtrim((string) $path, '/\\');
        if ($path === '' || array_search($path, static::instance()->languagePath) !== false) {
            return;
        }
        static::instance()->languagePath[] = $path;
    }

    /**
     * 获取所有搜索路径
     *
     * @return array
     */
    public static function getPaths()
    {
        return static::instance()->languagePath;
    }

    // ===================== 消息管理 =====================

    /**
     * 注册 / 批量注册翻译消息
     *
     * @param string|array $key
     * @param mixed|null   $value
     */
    public static function with($key, $value = null)
    {
        $instance = static::instance();

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                if (is_array($v)) {
                    static::mergeMessages((string) $k, $v);
                } else {
                    $instance->messages[$k] = $v;
                }
            }
            return;
        }

        if (is_array($value)) {
            static::mergeMessages((string) $key, $value);
            return;
        }

        $instance->messages[$key] = $value;
    }

    /**
     * 合并一组消息到指定命名空间（同名的后写入优先）
     *
     * @param string $namespace 语言文件名，如 validator
     * @param array  $messages
     */
    protected static function mergeMessages(string $namespace, array $messages): void
    {
        $instance = static::instance();
        $current  = $instance->messages[$namespace] ?? [];
        $instance->messages[$namespace] = is_array($current)
            ? array_replace_recursive($current, $messages)
            : $messages;
    }

    /**
     * 以 $base 为底层、已加载译文为上层做合并（回退语言专用）
     *
     * @param string $namespace
     * @param array  $base
     */
    protected static function mergeMessagesUnder(string $namespace, array $base): void
    {
        $instance = static::instance();
        $current  = $instance->messages[$namespace] ?? [];
        $instance->messages[$namespace] = is_array($current)
            ? array_replace_recursive($base, $current)
            : $base;
    }

    /**
     * 已加载标记键（区分语言，切换语言后自动重新加载）
     *
     * @param string $language
     * @param string $name
     * @return string
     */
    protected static function loadedKey(string $language, string $name): string
    {
        return $language . '::' . $name;
    }

    /**
     * 设置单个翻译
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function set($key, $value)
    {
        static::instance()->messages[$key] = $value;
    }

    /**
     * 获取翻译原始文本（不做替换）
     *
     * @param string $key      点号分隔的 key，支持嵌套取值
     * @param mixed  $default  未找到时的默认值
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $instance = static::instance();

        // 点号嵌套 key：validator.rule_email → messages['validator']['rule_email']
        if (str_contains($key, '.') && !isset($instance->messages[$key])) {
            $segments = explode('.', $key);
            $value = $instance->messages;
            foreach ($segments as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default ?? $key;
                }
                $value = $value[$segment];
            }
            return $value;
        }

        if (isset($instance->messages[$key])) {
            return $instance->messages[$key];
        }

        return $default ?? $key;
    }

    /**
     * 检查翻译 key 是否存在
     *
     * @param string $key
     * @return bool
     */
    public static function has($key)
    {
        if (str_contains($key, '.')) {
            $segments = explode('.', $key);
            $value = static::instance()->messages;
            foreach ($segments as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return false;
                }
                $value = $value[$segment];
            }
            return true;
        }
        return array_key_exists($key, static::instance()->messages);
    }

    // ===================== 翻译方法 =====================

    /**
     * 获取翻译文本并支持参数替换
     *
     * @param string $name   文件名.key 格式，如 'validator.rule_email'
     * @param array|string|null $params  替换参数 [key => value] 或替换 {value} 的字符串
     * @param mixed  $value  当 $params 为字符串时的值（已废弃推荐用数组）
     * @return string
     */
    public static function trans($name, $params = null, $value = null)
    {
        $parts = explode('.', $name, 2);

        // 没有命名空间的裸 key：直接在已注册消息里查找
        if (count($parts) < 2) {
            return static::applyParams(static::get($name), $params, $value);
        }

        [$filename, $msgKey] = $parts;

        // 按「当前语言 + 文件名」缓存，切换语言后自动重新加载
        static::load($filename);

        $message = static::get($name);

        // 回退语言
        if (!is_string($message) || $message === $name) {
            $fallback = static::loadFallback($filename, $msgKey);
            if ($fallback !== null) {
                $message = $fallback;
            }
        }

        return static::applyParams($message, $params, $value);
    }

    /**
     * 把参数替换进译文
     *
     * @param mixed            $message
     * @param array|string|null $params
     * @param mixed            $value
     * @return string
     */
    protected static function applyParams($message, $params = null, $value = null): string
    {
        if (is_string($message)) {
            // ok
        } elseif (is_scalar($message) || $message === null) {
            $message = (string) $message;
        } else {
            $message = '';
        }

        if ($value !== null) {
            if (!is_array($params)) {
                $params = ['value' => $params];
            }
            $params['value'] = $value;
        }

        if ($params === null) {
            return $message;
        }

        // 兼容旧用法：trans($name, '标量值') → 替换 {value}
        if (!is_array($params)) {
            $params = ['value' => $params];
        }

        if ($params === []) {
            return $message;
        }

        return Str::format($message, $params);
    }

    /**
     * 复数翻译
     *
     * @param string $name   文件名.key 格式
     * @param int    $count  数量
     * @param array  $params 替换参数
     * @return string
     */
    public static function transChoice($name, $count, $params = [])
    {
        $parts = explode('.', $name, 2);
        if (count($parts) < 2) {
            $params['count'] = $count;
            return static::applyParams(static::get($name), $params);
        }

        [$filename, $msgKey] = $parts;

        static::load($filename);

        // 按复数规则查找：name.one, name.other
        $choice = static::resolvePluralKey($name, $count);

        if ($choice !== null) {
            $message = static::get($choice);
        } else {
            // 尝试 {name}.{count} 精确匹配，否则使用原始 key
            $exactKey = $name . '.' . $count;
            $message = static::get($exactKey);
            if (!is_string($message) || $message === $exactKey) {
                $message = static::get($name);
            }
        }

        // 回退语言
        if (!is_string($message) || $message === $name) {
            $fallback = static::loadFallback($filename, $msgKey);
            if ($fallback !== null) {
                $message = $fallback;
            }
        }

        $params['count'] = $count;
        return static::applyParams($message, $params);
    }

    // ===================== 内部方法 =====================

    /**
     * 加载语言文件
     *
     * @param string $name 文件名（不含扩展名）
     */
    public static function load($name)
    {
        $instance = static::instance();
        $language = static::normalizeLocale($instance->language);
        $loadedKey = static::loadedKey($language, $name);

        // 同一语言下每个文件只加载一次
        if (isset($instance->loaded[$loadedKey])) {
            return $instance->loaded[$loadedKey];
        }

        $loaded = false;

        foreach ($instance->languagePath as $path) {
            $pack = static::readLanguageFile($path, $language, $name);
            if ($pack !== null) {
                // 多个搜索路径按「后注册者优先」合并，app 的译文可以只写差异项
                static::mergeMessages($name, $pack);
                $loaded = true;
            }
        }

        $instance->loaded[$loadedKey] = $loaded;
        return $loaded;
    }

    /**
     * 读取单个语言目录下的语言文件内容
     *
     * @param string $path     搜索路径
     * @param string $language 语言
     * @param string $name     文件名（不含扩展名）
     * @return array|null 不存在或格式不正确时返回 null
     */
    protected static function readLanguageFile(string $path, string $language, string $name): ?array
    {
        $phpFile = $path . "/{$language}/{$name}.php";
        if (is_file($phpFile)) {
            $_LANG = include $phpFile;
            if (is_array($_LANG)) {
                return $_LANG;
            }
        }

        $jsonFile = $path . "/{$language}/{$name}.json";
        if (is_file($jsonFile)) {
            $jsonData = json_decode((string) file_get_contents($jsonFile), true);
            if (is_array($jsonData)) {
                return $jsonData;
            }
        }

        return null;
    }

    /**
     * 从回退语言加载翻译（只补齐当前语言缺失的 key）
     */
    protected static function loadFallback($filename, $msgKey)
    {
        $instance = static::instance();
        $fallbackLanguage = static::normalizeLocale($instance->fallbackLanguage);
        $fullKey = $filename . '.' . $msgKey;

        if ($fallbackLanguage === '' || $fallbackLanguage === static::normalizeLocale($instance->language)) {
            return null;
        }

        $loadedKey = 'fallback::' . static::loadedKey($fallbackLanguage, $filename);

        // 已经尝试过回退语言加载
        if (isset($instance->loaded[$loadedKey])) {
            return static::has($fullKey) ? static::get($fullKey) : null;
        }

        $fallbackPack = [];
        foreach ($instance->languagePath as $path) {
            $pack = static::readLanguageFile($path, $fallbackLanguage, $filename);
            if ($pack !== null) {
                $fallbackPack = array_replace_recursive($fallbackPack, $pack);
            }
        }

        $instance->loaded[$loadedKey] = true;

        if ($fallbackPack !== []) {
            // 当前语言已有译文优先，回退语言只补空缺
            static::mergeMessagesUnder($filename, $fallbackPack);
        }

        return static::has($fullKey) ? static::get($fullKey) : null;
    }

    /**
     * 根据复数规则解析 key
     *
     * @param string $fullName  完整 key，如 'messages.apples'
     * @param int    $count     数量
     * @return string|null      如 'messages.apples.one' 或 'messages.apples.other'
     */
    protected static function resolvePluralKey($fullName, $count)
    {
        $one = $fullName . '.one';
        if ($count === 1 && static::has($one)) {
            return $one;
        }

        $other = $fullName . '.other';
        if (static::has($other)) {
            return $other;
        }

        return null;
    }
}
