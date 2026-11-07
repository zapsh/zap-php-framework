<?php

namespace zap\traits;

/**
 * 单例 trait
 */
trait SingletonTrait
{
    /** @var array<class-string, static> 按实际类名分别持有实例 */
    private static array $instances = [];

    protected function __construct()
    {
    }

    /** 禁止外部克隆单例实例 */
    protected function __clone()
    {
    }

    /**
     * 获取单例（首次创建时自动调用 init()）
     *
     * @return static
     */
    public static function instance(): static
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            $instance = new static();
            $instance->init();
            self::$instances[$class] = $instance;
        }
        return self::$instances[$class];
    }

    /**
     * 注入/替换实例（测试用），传 null 等价于重置
     *
     * 注入的实例不会触发 init()，由调用方自行保证其状态。
     */
    public static function setInstance(?object $instance): void
    {
        $class = static::class;
        if ($instance === null) {
            unset(self::$instances[$class]);
            return;
        }
        if (!$instance instanceof $class) {
            throw new \InvalidArgumentException(sprintf(
                '%s::setInstance() 需要 %s 实例，收到 %s',
                $class,
                $class,
                get_class($instance)
            ));
        }
        self::$instances[$class] = $instance;
    }

    public static function hasInstance(): bool
    {
        return isset(self::$instances[static::class]);
    }

    /** 重置单例（主要用于测试与长驻进程） */
    public static function resetInstance(): void
    {
        unset(self::$instances[static::class]);
    }

    /**
     * 首次创建后的初始化钩子
     */
    protected function init()
    {
    }
}
