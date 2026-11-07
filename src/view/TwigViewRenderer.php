<?php

namespace zap\view;

use \Exception;
use Twig\Loader\FilesystemLoader;

class TwigViewRenderer extends ViewRenderer
{
    /** @var FilesystemLoader */
    protected FilesystemLoader $loader;

    /** @var \Twig\Environment */
    protected \Twig\Environment $twigEngine;

    /** @var array 绝对路径 → Twig 模板名 缓存 */
    private static $nameCache = [];

    public function __construct(View $view)
    {
        parent::__construct($view);

        // Twig FilesystemLoader 构造时会校验所有路径必须存在，
        // 过滤掉不存在的搜索路径（如未使用 app/views 目录的项目），避免 LoaderError
        $existingPaths = array_values(array_filter(
            View::getPath(),
            function (string $path): bool { return is_dir($path); }
        ));

        $this->loader = new FilesystemLoader($existingPaths ?: [base_path()]);

        $templatePaths = config('twig.template_paths', []);
        foreach ($templatePaths as $namespace => $templateDir) {
            $ns = is_int($namespace) ? FilesystemLoader::MAIN_NAMESPACE : $namespace;
            $this->loader->addPath($templateDir, $ns);
        }

        $options = config('twig.options', ['cache' => false, 'debug' => true]);
        $options['debug'] = config('config.debug', false);

        $this->twigEngine = new \Twig\Environment($this->loader, $options);

        $extensions = config('twig.extensions', []);
        foreach ($extensions as $extension) {
            if (is_string($extension) && class_exists($extension)) {
                $this->twigEngine->addExtension(new $extension());
            } elseif (is_array($extension) && isset($extension[0]) && class_exists($extension[0])) {
                $this->twigEngine->addExtension(
                    new $extension[0](...($extension[1] ?? []))
                );
            }
        }
    }

    /**
     * 渲染 Twig 视图
     */
    public function render(bool $output = false): ?string
    {
        // 合并全局数据（不覆盖已有同名本地数据），使用局部副本，避免污染视图实例
        $params = $this->view->params;
        foreach (View::$globalData as $name => $value) {
            if (!array_key_exists($name, $params)) {
                $params[$name] = $value;
            }
        }

        // 支持布局：把布局名传入模板变量（局部副本）
        if ($this->view->layout) {
            $params['_zap_layout'] = $this->view->layout;
        }

        $templateName = $this->getTemplateName($this->view->viewFile);
        if ($output) {
            return $this->twigEngine->render($templateName, $params);
        }
        $this->twigEngine->display($templateName, $params);
        return null;
    }

    /**
     * 渲染局部模板
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $params = array_merge($this->view->params, $data);
        foreach (View::$globalData as $name => $value) {
            if (!array_key_exists($name, $params)) {
                $params[$name] = $value;
            }
        }
        return $this->twigEngine->render(
            $this->getTemplateName($template),
            $params
        );
    }

    /**
     * 获取 Twig 模板名称（相对于 loader 根路径）
     *
     * 修复：不再仅使用 basename()，保留子目录路径
     */
    private function getTemplateName(string $fullPath): string
    {
        $fullPath = str_replace('\\', '/', $fullPath);
        if (isset(static::$nameCache[$fullPath])) {
            return static::$nameCache[$fullPath];
        }
        $name = $this->resolveTemplateName($fullPath);
        static::$nameCache[$fullPath] = $name;
        return $name;
    }

    /**
     * 计算 Twig 模板名称（相对于 loader 根路径）
     */
    private function resolveTemplateName(string $fullPath): string
    {
        foreach (View::getPath() as $basePath) {
            $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
            if (strpos($fullPath, $basePath . '/') === 0) {
                // 必须保留 .twig 后缀：Twig 的 FilesystemLoader 按完整文件名查找，不会自动补后缀
                return $this->ensureTwigExtension(substr($fullPath, strlen($basePath) + 1));
            }
        }
        // 兜底：使用 basename
        return $this->ensureTwigExtension(basename($fullPath));
    }

    /**
     * 确保模板名带 .twig 后缀（renderPartial('header') 这类调用也能正常工作）
     */
    private function ensureTwigExtension(string $name): string
    {
        if ($name === '') {
            return $name;
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'twig') {
            return $name;
        }
        return $name . '.twig';
    }

    public function getLoader(): FilesystemLoader
    {
        return $this->loader;
    }

    public function getEnvironment(): \Twig\Environment
    {
        return $this->twigEngine;
    }
}
