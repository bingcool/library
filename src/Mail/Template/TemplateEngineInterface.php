<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Template;

/**
 * 模板名加变量，得到 HTML 字符串。Blade / Twig 由业务实现本接口后注入，组件不内置它们的语法。
 */
interface TemplateEngineInterface
{
    /**
     * @param array<string, scalar|null> $vars
     */
    public function render(string $name, array $vars): string;
}
