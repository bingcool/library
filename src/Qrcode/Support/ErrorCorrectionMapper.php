<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use Swoolefy\Library\Qrcode\Exception\InvalidQrcodeContentException;

/** 将配置/Builder 中的 L|M|Q|H 映射为 Bacon {@see ErrorCorrectionLevel} */
final class ErrorCorrectionMapper
{
    /**
     * @throws InvalidQrcodeContentException 非 L/M/Q/H
     */
    public static function map(string $level): ErrorCorrectionLevel
    {
        return match (strtoupper(trim($level))) {
            'L' => ErrorCorrectionLevel::L(),
            'M' => ErrorCorrectionLevel::M(),
            'Q' => ErrorCorrectionLevel::Q(),
            'H' => ErrorCorrectionLevel::H(),
            default => throw new InvalidQrcodeContentException(sprintf('Unknown QR error correction level [%s]', $level)),
        };
    }
}
