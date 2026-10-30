<?php

namespace zap\http;

use Closure;

/**
 * HTTP 中间件契约
 *
 * 实现示例：
 *
 *   class AuthMiddleware implements Middleware
 *   {
 *       public function handle(ZapRequest $request, Closure $next)
 *       {
 *           // 校验不通过可直接返回响应，不必调用 $next
 *           return $next();
 *       }
 *   }
 *
 * 注意：handle() 必须接收两个参数，与 Route::runMiddlewaresAndHandler()
 * 的调用约定 ($request, $next) 保持一致。
 */
interface Middleware
{
    /**
     * @param ZapRequest $request 当前请求
     * @param Closure    $next    下一个中间件/最终处理器
     * @return mixed
     */
    public function handle(ZapRequest $request, Closure $next);
}
