<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail;

/**
 * 邮箱地址和显示名。构造时即完成校验，非法地址进不了后续流程。
 */
final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
        Support\AddressGuard::assert($this->email, $this->name);
    }
}
