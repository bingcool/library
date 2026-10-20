<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Contracts;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * 只负责把一种传输方式写进一个新的 PHPMailer。不发送，也不保存连接。
 */
interface TransportInterface
{
    /**
     * 把主机、端口、加密、认证等写进传入的 PHPMailer。
     * 每次发送都针对新实例调用，不得在这里建立或复用连接。
     */
    public function apply(PHPMailer $mailer): void;

    /**
     * @return string 传输名，用于日志、异常分支和部分投递观测
     */
    public function name(): string;
}
