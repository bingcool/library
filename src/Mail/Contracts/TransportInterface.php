<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Contracts;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * 只负责把一种传输方式写进一个新的 PHPMailer。不发送，也不保存连接。
 */
interface TransportInterface
{
    public function apply(PHPMailer $mailer): void;

    public function name(): string;
}
