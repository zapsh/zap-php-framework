<?php

namespace zap\http;

use zap\exception\NotFoundException;

class Route
{
    /** @var string 路由模式 */
    public string $pattern;

    /** @var mixed 处理器（callable 或 'Controller@method'） */
    public $fn;

    /** @var array 支持的 HTTP 方法 */
    public array $methods = [];

    /** @var array 匹配后提取的参数 */
    public array $params = [];

    /** @var array 中间件类名列表 */
    protected array $middlewares = [];

    /** @var string|null 路由名称 */
    protected ?string $routeName = null;

    /** @var string|null 路由组名 */
    protected ?string $groupName = null;

    /** @var string|null 预编译的正则模式（路由缓存时填充，避免每次请求重新构建） */
    protected ?string $compiledRegex = null;

    public function __construct(string $pattern, $fn, array $methods = ['GET'])
    {
        $this->pattern = $pattern;
        $this->fn      = $fn;
        $this->methods = $methods;
    }

    // ───────────────────── 模式匹配 ─────────────────────

    /**
     * 编译路由模式为正则（结果缓存复用）
     */
    public function compilePattern(): string
    {
        if ($this->compiledRegex !== null) {
            return $this->compiledRegex;
        }

        // 转义 / 并处理 {any} 通配符
        $regex = '/^' . str_replace(['/', '{any}'], ['\/', '([^/]+)'], $this->pattern) . '$/';

        // 替换命名的占位符 {name:pattern}
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):([^}]+)\}/', '(?P<$1>$2)', $regex);

        // 替换简单占位符 {name}
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $regex);

        $this->compiledRegex = $regex;
        return $regex;
    }

    /**
     * 尝试将 URL 匹配到路由模式
     */
    public function matchPattern(string $url): bool
    {
        $pattern = $this->compilePattern();

        if (preg_match($pattern, $url, $matches)) {
            // 提取命名参数
            foreach ($matches as $k => $v) {
                if (is_string($k)) {
                    $this->params[$k] = $v;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * 导出为可缓存的数组（排除闭包等不可序列化的回调）
     *
     * @return array
     * @throws \RuntimeException 当处理器是闭包时
     */
    public function toCacheData(): array
    {
        if ($this->fn instanceof \Closure) {
            throw new \RuntimeException(
                "Cannot cache route '{$this->pattern}': closure handlers are not serializable. " .
                "Use 'Controller@method' string syntax instead."
            );
        }

        // 确保正则已编译
        $this->compilePattern();

        return [
            'pattern'       => $this->pattern,
            'fn'            => $this->fn,
            'methods'       => $this->methods,
            'middlewares'   => $this->middlewares,
            'routeName'     => $this->routeName,
            'groupName'     => $this->groupName,
            'compiledRegex' => $this->compiledRegex,
        ];
    }

    /**
     * 从缓存数据还原 Route 实例
     */
    public static function fromCacheData(array $data): self
    {
        $route = new self($data['pattern'], $data['fn'], $data['methods']);
        $route->compiledRegex = $data['compiledRegex'] ?? null;
        $route->middlewares   = $data['middlewares'] ?? [];
        $route->routeName     = $data['routeName'] ?? null;
        $route->groupName     = $data['groupName'] ?? null;
        return $route;
    }

    // ───────────────────── 中间件 ─────────────────────

    /**
     * 添加中间件
     *
     * @param string $middleware 中间件类名
     * @return $this
     */
    public function middleware(string $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * 获取中间件列表
     */
    public function getMiddleware(): array
    {
        return $this->middlewares;
    }

    // ───────────────────── 命名路由 ─────────────────────

    /**
     * 设置路由名称
     *
     * @return $this
     */
    public function name(string $name): self
    {
        $this->routeName = $name;
        Router::name($name, $this->pattern);
        return $this;
    }

    /**
     * 设置路由组名称
     *
     * @return $this
     */
    public function group(string $groupName): self
    {
        $this->groupName = $groupName;
        return $this;
    }

    /**
     * 获取路由名称
     */
    public function getName(): ?string
    {
        return $this->routeName;
    }

    // ───────────────────── 调用 ─────────────────────

    /**
     * 执行路由处理器
     *
     * @param array $params 路由参数
     */
    public function invoke(array $params = []): void
    {
        if (is_object($this->fn) && ($this->fn instanceof \Closure)) {
            // 闭包回调
            $this->runMiddlewaresAndHandler(function () use ($params) {
                return call_user_func_array($this->fn, $params);
            });
            return;
        }

        if (is_string($this->fn) && strpos($this->fn, '@') !== false) {
            // Controller@method 格式
            [$controllerClass, $method] = explode('@', $this->fn, 2);

            // 保存到 Router 供全局访问（如 UrlHelper 用于 active 匹配）
            // 这一段失败不影响请求处理，因此只做信息补充
            try {
                $shortName = (new \ReflectionClass($controllerClass))->getShortName();
                $controllerName = strtolower(preg_replace('/Controller$/i', '', $shortName));
                app()->router->controller = $controllerName;
                app()->router->method = strtolower($method);
            } catch (\Throwable $e) {
                // 控制器不可用时由下面的检查统一抛出 404
            }

            if (!class_exists($controllerClass)) {
                throw new NotFoundException("Controller not found: {$controllerClass}");
            }

            if (!method_exists($controllerClass, $method)) {
                throw new NotFoundException("Action not found: {$controllerClass}@{$method}");
            }

            try {
                $reflectedMethod = new \ReflectionMethod($controllerClass, $method);
            } catch (\ReflectionException $e) {
                throw new NotFoundException("Action not found: {$controllerClass}@{$method}", 0, $e);
            }

            // 非 public / 抽象方法不可作为路由入口，
            // 必须明确抛错，否则会静默返回空响应且难以排查
            if (!$reflectedMethod->isPublic() || $reflectedMethod->isAbstract()) {
                throw new NotFoundException("Action is not accessible: {$controllerClass}@{$method}");
            }

            if ($reflectedMethod->isStatic()) {
                $this->runMiddlewaresAndHandler(function () use ($controllerClass, $method, $params) {
                    return call_user_func_array([$controllerClass, $method], $params);
                });
                return;
            }

            $this->runMiddlewaresAndHandler(function () use ($controllerClass, $method, $params) {
                // 通过容器解析，使控制器支持构造注入；失败时回退到直接实例化
                $instance = $this->resolveInstance($controllerClass);
                if (method_exists($instance, 'setParams')) {
                    $instance->setParams($params);
                }
                return call_user_func_array([$instance, $method], $params);
            });

            return;
        }

        if (is_callable($this->fn)) {
            // 直接可调用的
            $this->runMiddlewaresAndHandler(function () use ($params) {
                return call_user_func_array($this->fn, $params);
            });
            return;
        }

        // 处理器既不是闭包、也不是 Controller@method 或可调用值
        throw new NotFoundException('Route handler is not callable for pattern: ' . $this->pattern);
    }

    /**
     * 解析类实例：优先通过 IoC 容器以支持构造注入
     *
     * 容器不可用或解析失败时回退到 new $class()，
     * 保证既有代码不会因为容器限制而突然失效。
     */
    protected function resolveInstance(string $class): object
    {
        try {
            if (function_exists('app') && app() !== null) {
                return app()->make($class);
            }
        } catch (\Throwable $e) {
            // 忽略，走下面的回退分支
        }

        return new $class();
    }

    /**
     * 运行中间件链，然后执行处理器
     */
    protected function runMiddlewaresAndHandler(callable $handler): void
    {
        if (empty($this->middlewares)) {
            $handler();
            return;
        }

        // 构建中间件链
        $middlewares = $this->middlewares;
        $next = function () use (&$middlewares, &$next, $handler) {
            if (empty($middlewares)) {
                return $handler();
            }
            $middlewareClass = array_shift($middlewares);
            // 中间件同样走容器解析，便于注入服务；失败时回退到直接实例化
            $middleware = $this->resolveInstance($middlewareClass);
            return $middleware->handle(ZapRequest::instance(), $next);
        };

        $next();
    }
}
