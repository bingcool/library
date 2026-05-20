<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Http;

final class HttpStatus
{
    public const OK = 200;

    public const NOT_FOUND = 404;

    private const REASONS = [
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    public static function getReasonPhrase(int $code): string
    {
        return self::REASONS[$code] ?? 'Unknown Status';
    }

    private function __construct()
    {
    }
}
