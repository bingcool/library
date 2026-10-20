<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 从 HTML 抽出纯文本，作为没有 text 正文时的 AltBody。链接保留 URL。
 */
final class HtmlToText
{
    /**
     * 先把 <a href> 转成「文本 (URL)」保留链接，再去标签、解实体、压缩空白。
     * 正则失败时回退原文继续处理，纯文本降级不应让发送失败。
     */
    public static function convert(string $html): string
    {
        $withLinks = preg_replace_callback(
            '/<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']*)\1[^>]*>(.*?)<\/a>/is',
            static function (array $match): string {
                $url = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($url === '') {
                    return $text;
                }
                // 文本为空或与 URL 相同时只保留 URL，避免生成 "url (url)"
                if ($text === '' || $text === $url) {
                    return $url;
                }

                return $text . ' (' . $url . ')';
            },
            $html
        );

        $plain = html_entity_decode(strip_tags($withLinks ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace("/[ \t]+\n/", "\n", $plain) ?? $plain;
        $plain = preg_replace("/\n{3,}/", "\n\n", $plain) ?? $plain;

        return trim($plain);
    }
}
