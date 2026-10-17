<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\MailMessage;

/**
 * 把附件和内嵌图收成可交给 PHPMailer 的规格，并按字节数合计封顶。
 * 路径里出现 .. 直接拒绝，不跟到真实路径之外。
 */
final class AttachmentNormalizer
{
    /**
     * @return array{
     *     attachments: list<array{path?: string, data?: string, name: string, mime: string}>,
     *     embeds: list<array{path?: string, data?: string, cid: string, name: string}>
     * }
     */
    public function normalize(MailMessage $message, int $maxBytes): array
    {
        $total = 0;
        $attachments = [];
        foreach ($message->attachments as $item) {
            $name = (string) ($item['name'] ?? '');
            AddressGuard::assertHeader($name, 'attachment name');
            if (isset($item['data'])) {
                $data = (string) $item['data'];
                $total += strlen($data);
                $attachments[] = [
                    'data' => $data,
                    'name' => $name !== '' ? $name : 'attachment.bin',
                    'mime' => (string) ($item['mime'] ?? ''),
                ];
                continue;
            }

            $path = $this->readableFile((string) ($item['path'] ?? ''));
            $total += filesize($path) ?: 0;
            $attachments[] = [
                'path' => $path,
                'name' => $name !== '' ? $name : basename($path),
                'mime' => (string) ($item['mime'] ?? ''),
            ];
        }

        $embeds = [];
        foreach ($message->embeds as $item) {
            $cid = (string) ($item['cid'] ?? '');
            if ($cid === '') {
                throw new InvalidMailMessageException('Embedded image cid is required');
            }
            AddressGuard::assertHeader($cid, 'embed cid');
            $path = $this->readableFile((string) ($item['path'] ?? ''));
            $total += filesize($path) ?: 0;
            $embeds[] = [
                'path' => $path,
                'cid' => $cid,
                'name' => basename($path),
            ];
        }

        if ($total > $maxBytes) {
            throw new InvalidMailMessageException(sprintf(
                'Mail attachments exceed %d bytes',
                $maxBytes
            ));
        }

        return ['attachments' => $attachments, 'embeds' => $embeds];
    }

    private function readableFile(string $path): string
    {
        if ($path === '' || preg_match('#(?:^|[/\\\\])\.\.(?:$|[/\\\\])#', $path) === 1) {
            throw new InvalidMailMessageException('Mail attachment path is not allowed');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new InvalidMailMessageException('Mail attachment is not a readable file');
        }

        return $real;
    }
}
