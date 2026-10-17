<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Address;

/**
 * 校验并渲染之后的一封信。传输层只消费这个对象。
 */
final class PreparedMessage
{
    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<array{path?: string, data?: string, name: string, mime: string}> $attachments
     * @param list<array{path?: string, data?: string, cid: string, name: string}> $embeds
     */
    public function __construct(
        public readonly Address $from,
        public readonly ?Address $replyTo,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly string $subject,
        public readonly ?string $html,
        public readonly ?string $text,
        public readonly array $attachments,
        public readonly array $embeds,
    ) {
    }
}
