<?php

namespace zap\tests\http;

use PHPUnit\Framework\TestCase;
use zap\exception\NotFoundException;
use zap\http\Middleware;
use zap\http\Route;
use ReflectionMethod;

/**
 * 路由调度的回归测试
 *
 * 背景：Route::invoke() 原先有一个空的 catch，控制器/方法不存在时
 * 既不返回 404 也不报错，只是静默产生一个空响应。
 */
final class RouteNotFoundTest extends TestCase
{
    public function testMissingControllerThrowsNotFound(): void
    {
        $route = $this->makeRoute('NoSuchNamespace\\GhostController@index');

        $this->expectException(NotFoundException::class);
        $route->invoke([]);
    }

    public function testMissingActionThrowsNotFound(): void
    {
        $route = $this->makeRoute('zap\\view\\View@thisActionDoesNotExist');

        $this->expectException(NotFoundException::class);
        $route->invoke([]);
    }

    public function testNonCallableHandlerThrowsNotFound(): void
    {
        $route = $this->makeRoute('plain-string-not-callable');

        $this->expectException(NotFoundException::class);
        $route->invoke([]);
    }

    /**
     * 中间件接口必须与实际调用约定一致：handle($request, $next)
     */
    public function testMiddlewareContractMatchesInvocation(): void
    {
        $method = new ReflectionMethod(Middleware::class, 'handle');

        $this->assertSame(2, $method->getNumberOfParameters());
    }

    private function makeRoute(string $handler): Route
    {
        return Route::fromCacheData([
            'pattern'       => '/probe',
            'fn'            => $handler,
            'methods'       => ['GET'],
            'compiledRegex' => null,
        ]);
    }
}
