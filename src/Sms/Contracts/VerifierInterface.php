<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Contracts;

use Swoolefy\Library\Sms\ValueObject\SendResult;
use Swoolefy\Library\Sms\ValueObject\VerifyResult;

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
     * 校验用户输入的验证码。
     *
     * 成功即销毁该场景的码、错误次数、冷却和发送痕迹。
     * 失败不抛异常，用 VerifyResult::$status 区分：
     * mismatch（还可试 remaining 次）、expired（发过已过期）、
     * absent（没发过）、locked（错误次数用尽已作废）。
     *
     * @param string $phone 目标号码；会归一化，同一号码的不同写法共用同一验证码
     * @param string $input 用户输入的验证码
     * @param string $template 场景，与 send() 的模板一致，如 login_code / register_code
     * @throws \Swoolefy\Library\Sms\Exception\InvalidPhoneException 号码为空或格式非法
     */
    public function verify(string $phone, string $input, string $template = 'login_code'): VerifyResult;
}
