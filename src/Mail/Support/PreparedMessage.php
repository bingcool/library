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
     * 构造时消息已完成全部校验：地址、主题、正文、from 一致性、附件限额和模板渲染。
     * 传输层不再修改这些字段。
     *
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<array{data: string, name: string, mime: string}> $attachments
     * @param list<array{data: string, cid: string, name: string}> $embeds
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
