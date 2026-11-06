<?php

namespace zap\view;

abstract class ViewRenderer
{
    /**
     * @var View
     */
    protected View $view;

    public function __construct($view)
    {
        $this->view = $view;
    }

    /**
     * 渲染视图
     *
     * @param bool $output true=返回字符串, false=直接输出
     * @return string|null
     */
    abstract public function render(bool $output = false): ?string;

    /**
     * 渲染指定模板文件（内部使用，子类可覆盖）
     *
     * @param string $template  模板文件绝对路径
     * @param string $aliasName 块别名（用于布局系统）
     */
    public function renderTemplate(string $template, string $aliasName = 'content'): void
    {
    }

    /**
     * 渲染局部模板，返回纯内容（不缓存为块，不参与布局）
     *
     * @param string $template 模板文件绝对路径
     * @param array  $data     额外数据
     * @return string
     */
    public function renderPartial(string $template, array $data = []): string
    {
        return '';
    }

    // ──────────── 委托给 View 的方法 ────────────

    public function layout(string $layout): void
    {
        $this->view->setLayout($layout);
    }

    public function extend(string $layout): void
    {
        $this->view->setLayout($layout);
    }

    public function include(string $name, string $blockName = '_include'): void
    {
        $this->view->include($name, $blockName);
    }

    public function partial(string $name, array $data = []): string
    {
        return $this->view->partial($name, $data);
    }

    public function block(string $name): string
    {
        return $this->view->blocks[$name] ?? '';
    }

    public function section(string $name): string
    {
        return $this->block($name);
    }

    public function beginBlock(string $name): void
    {
        $this->view->beginBlock($name);
    }

    public function endBlock(): void
    {
        $this->view->endBlock();
    }

    /**
     * HTML 转义（在模板中使用：$this->e($var) 或 $this->esc($var)）
     */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function esc(mixed $value): string
    {
        return $this->e($value);
    }
}
