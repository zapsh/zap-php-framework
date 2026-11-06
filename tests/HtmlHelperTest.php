<?php
/*
 * Copyright (c) 2025.  ZAP.CN  - ZAP CMS - All Rights Reserved
 * @author Allen
 * @email zapcms@zap.cn
 * @date 2025/4/23 17:51
 * @lastModified 2025/4/23 17:51
 *
 */


use zap\html\Html;

final class HtmlHelperTest extends \PHPUnit\Framework\TestCase {
    public function testHtmlHelper() {
        $form = Html::form('/html', 'get');
        // Html::a($href, $content) 返回 Element；html() 接受的是 HTML 字符串
        $form->html(Html::a('/html', 'hello')->render());

        $html = $form->render();

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('action="/html"', $html);
        $this->assertStringContainsString('<a href="/html">hello</a>', $html);
    }
}
