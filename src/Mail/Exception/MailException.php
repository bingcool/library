<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Exception;

/**
 * Mail 模块异常基类。业务可以 catch (MailException) 收口。
 * Job 只应对 MailTransportException 重试，其余子类重试也不会变好。
 */
class MailException extends \RuntimeException
{
}
