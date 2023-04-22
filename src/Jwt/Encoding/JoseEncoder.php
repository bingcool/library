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

namespace Common\Library\Jwt\Encoding;

use JsonException;
use Common\Library\Jwt\Decoder;
use Common\Library\Jwt\Encoder;

final class JoseEncoder implements Encoder, Decoder
{
    private const JSON_DEFAULT_DEPTH = 512;

    /** @inheritdoc */
    public function jsonEncode($data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CannotEncodeContent::jsonIssues($exception);
        }
    }

    /** @inheritdoc */
    public function jsonDecode(string $json)
    {
        try {
            return json_decode($json, true, self::JSON_DEFAULT_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CannotDecodeContent::jsonIssues($exception);
        }
    }

    public function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $data): string
    {
        // Padding isn't added back because it isn't strictly necessary for decoding with PHP
        $decodedContent = base64_decode(strtr($data, '-_', '+/'), true);

        if (! is_string($decodedContent)) {
            throw CannotDecodeContent::invalidBase64String();
        }

        return $decodedContent;
    }
}
