<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Contracts;

use Swoolefy\Library\Sms\ValueObject\SendResult;

/**
 * 短信验证码服务抽象：生成 + 前置流控 + 发送 + 校验。
 */
interface VerifierInterface
{
    /**
     * 生成验证码并发送；命中限流 / 重发间隔抛 `RateLimitExceededException`。
     *
     * @param string $phone 目标号码
     * @param string $template 模板别名或真实 code
     */
    public function send(string $phone, string $template = 'login_code'): SendResult;

    /**
     * 校验用户输入的验证码；成功即销毁，失败累计并在超过上限后失效，防暴力猜测。
     */
    public function verify(string $phone, string $input, string $template = 'login_code'): bool;
}
