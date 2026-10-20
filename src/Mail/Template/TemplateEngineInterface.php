<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Template;

/**
 * 模板名加变量，得到 HTML 字符串。Blade / Twig 由业务实现本接口后注入，组件不内置它们的语法。
 */
interface TemplateEngineInterface
{
    /**
     * 渲染模板并返回 HTML。实现应自行处理模板名到文件的映射与越权访问防护。
     *
     * @param array<string, scalar|null> $vars 已完成合并和类型校验的变量表
     * @throws \Swoolefy\Library\Mail\Exception\MailException 模板不存在或渲染失败
     */
    public function render(string $name, array $vars): string;
}
