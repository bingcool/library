<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

/**
 * 从 HTML 抽出纯文本，作为没有 text 正文时的 AltBody。链接保留 URL。
 */
final class HtmlToText
{
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
