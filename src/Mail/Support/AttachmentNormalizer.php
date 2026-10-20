<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\MailMessage;

/**
 * 把附件和内嵌图读成字节并合计封顶。路径里出现 .. 直接拒绝，不跟到真实路径之外。
 * 统一转成内存数据后交给 PHPMailer，避免校验之后文件变化（TOCTOU）。
 */
final class AttachmentNormalizer
{
    /**
     * 读取全部附件和内嵌图，按附件 + 内嵌图合计计算上限。
     * 文件内容在这里一次性读入内存并随 PreparedMessage 传递，后续发送不再按路径读取，
     * 因此校验后替换文件不会改变实际发出的内容，也不会绕过大小限制。
     *
     * @param int $maxBytes 合计字节上限
     * @return array{
     *     attachments: list<array{data: string, name: string, mime: string}>,
     *     embeds: list<array{data: string, cid: string, name: string}>
     * }
     */
    public function normalize(MailMessage $message, int $maxBytes): array
    {
        $total = 0;
        $attachments = [];
        foreach ($message->attachmentList() as $item) {
            $name = (string) ($item['name'] ?? '');
            $mime = (string) ($item['mime'] ?? '');
            AddressGuard::assertHeader($name, 'attachment name');
            AddressGuard::assertMimeType($mime);

            if (isset($item['data'])) {
                $data = (string) $item['data'];
                $total += strlen($data);
                if ($total > $maxBytes) {
                    throw new InvalidMailMessageException(sprintf('Mail attachments exceed %d bytes', $maxBytes));
                }
                $attachments[] = [
                    'data' => $data,
                    'name' => $name !== '' ? $name : 'attachment.bin',
                    'mime' => $mime,
                ];
                continue;
            }

            $path = $this->readableFile((string) ($item['path'] ?? ''));
            $data = $this->readBytes($path, $maxBytes - $total, $maxBytes);
            $total += strlen($data);
            $attachments[] = [
                'data' => $data,
                'name' => $name !== '' ? $name : basename($path),
                'mime' => $mime,
            ];
        }

        $embeds = [];
        foreach ($message->embedList() as $item) {
            $cid = (string) ($item['cid'] ?? '');
            if ($cid === '') {
                throw new InvalidMailMessageException('Embedded image cid is required');
            }
            AddressGuard::assertHeader($cid, 'embed cid');
            $path = $this->readableFile((string) ($item['path'] ?? ''));
            $data = $this->readBytes($path, $maxBytes - $total, $maxBytes);
            $total += strlen($data);
            $embeds[] = [
                'data' => $data,
                'cid' => $cid,
                'name' => basename($path),
            ];
        }

        return ['attachments' => $attachments, 'embeds' => $embeds];
    }

    /**
     * 最多读取 remaining+1 字节：多出的 1 字节只用来判断超限，不信任 filesize()/stat，
     * 这样 stat 为 0 的特殊文件也无法绕过限额。
     *
     * @param int $remaining 当前封顶剩余可读字节
     * @param int $maxBytes 用于错误信息展示的总额
     */
    private function readBytes(string $path, int $remaining, int $maxBytes): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidMailMessageException('Mail attachment is not readable');
        }

        try {
            $data = stream_get_contents($handle, max(0, $remaining) + 1);
        } finally {
            fclose($handle);
        }

        if ($data === false) {
            throw new InvalidMailMessageException('Mail attachment is not readable');
        }
        if (strlen($data) > $remaining) {
            throw new InvalidMailMessageException(sprintf('Mail attachments exceed %d bytes', $maxBytes));
        }

        return $data;
    }

    /**
     * 解析并校验附件路径：拒绝任何 .. 段，realpath 后必须是可读的普通文件。
     * 先 realpath 再判断，确保符号链接也只能落到校验后的真实文件。
     */
    private function readableFile(string $path): string
    {
        if ($path === '' || preg_match('#(?:^|[/\\\\])\.\.(?:$|[/\\\\])#', $path) === 1) {
            throw new InvalidMailMessageException('Mail attachment path is not allowed');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new InvalidMailMessageException('Mail attachment is not a readable file');
        }
        $stat = @stat($real);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            throw new InvalidMailMessageException('Mail attachment is not a regular file');
        }

        return $real;
    }
}
