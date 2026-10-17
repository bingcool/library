<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Exception;

/**
 * 模板名不在白名单，或 view_path 下没有对应的 .html 文件。
 */
final class TemplateNotFoundException extends MailException
{
}
