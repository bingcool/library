<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */
declare(strict_types=1);

namespace Swoolefy\Library\CurlProxy;

use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * 安全读取 PSR-7 包体前 N 字节，供日志与 Span 属性使用。
 *
 * 只读取上限内的字节，避免大响应/大请求整段载入内存；不可读或不可 seek
 * 的流直接返回 null，绝不消费调用方尚未读取的数据。
 */
final class MessageBodyPreview
{
    private const READ_CHUNK_BYTES = 8192;

    /**
     * @return string|null 读取失败或流不可 seek 时返回 null；成功时最多返回 $maxBytes 字节
     */
    public static function read(StreamInterface $body, int $maxBytes): ?string
    {
        if ($maxBytes <= 0 || !$body->isReadable() || !$body->isSeekable()) {
            return null;
        }

        try {
            $body->rewind();
            $contents = '';
            $remaining = $maxBytes + 1;
            while ($remaining > 0) {
                $chunk = $body->read(min(self::READ_CHUNK_BYTES, $remaining));
                if ($chunk === '') {
                    break;
                }
                $contents .= $chunk;
                $remaining -= strlen($chunk);
            }
        } catch (Throwable) {
            return null;
        } finally {
            try {
                $body->rewind();
            } catch (Throwable) {
            }
        }

        if (strlen($contents) <= $maxBytes) {
            return $contents;
        }

        return self::truncate($contents, $maxBytes, $body->getSize());
    }

    /**
     * 按字节截断，并保证结果不超过 $maxBytes；不会切断 UTF-8 字符。
     */
    public static function truncate(string $contents, int $maxBytes, ?int $totalBytes = null): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        $size = strlen($contents);
        if ($size <= $maxBytes) {
            return $contents;
        }

        $suffix = $totalBytes !== null && $totalBytes > $maxBytes
            ? sprintf('...(truncated, %d bytes)', $totalBytes)
            : sprintf('...(truncated, >%d bytes)', $maxBytes);

        $keep = $maxBytes - strlen($suffix);
        if ($keep <= 0) {
            return function_exists('mb_strcut')
                ? mb_strcut($contents, 0, $maxBytes, 'UTF-8')
                : substr($contents, 0, $maxBytes);
        }

        $head = substr($contents, 0, $keep);
        if (function_exists('mb_strcut')) {
            $head = mb_strcut($head, 0, $keep, 'UTF-8');
        }

        return $head . $suffix;
    }
}
