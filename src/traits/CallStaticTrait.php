<?php

namespace zap\traits;

/**
 * 把静态调用转发到单例实例（SingletonTrait）
 */
trait CallStaticTrait
{
    /**
     * @param string $method    方法名
     * @param array  $arguments 参数列表
     * @return mixed
     * @throws \BadMethodCallException
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (!method_exists(static::class, 'instance')) {
            throw new \BadMethodCallException(
                static::class . 'instance method not found. Please use SingletonTrait or implement instance() method.'
            );
        }

        /** @var object $instance */
        $instance = static::instance();

        if (!is_callable([$instance, $method])) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s() does not exist.',
                static::class,
                $method
            ));
        }

        return $instance->$method(...$arguments);
    }
}
