<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode\Support;

use Picqer\Barcode\Barcode;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeCode39;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Types\TypeEan8;
use Picqer\Barcode\Types\TypeInterleaved25;
use Picqer\Barcode\Types\TypeUpcA;
use Swoolefy\Library\Barcode\BarcodeType;
use Swoolefy\Library\Barcode\Exception\InvalidBarcodeContentException;
use Throwable;

/**
 * 制式级校验 + 调用 picqer Type* 生成 {@see Barcode} 条数据对象。
 *
 * 在 picqer 抛错前尽量给出可读的业务异常 {@see InvalidBarcodeContentException}。
 */
final class TypeMapper
{
    /**
     * @throws InvalidBarcodeContentException 空内容、校验失败或 picqer 编码失败
     */
    public static function encode(BarcodeType $type, string $code): Barcode
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidBarcodeContentException('Barcode content is required');
        }

        self::validate($type, $code);

        try {
            return match ($type) {
                BarcodeType::CODE_128 => (new TypeCode128())->getBarcode($code),
                BarcodeType::CODE_39 => (new TypeCode39())->getBarcode(strtoupper($code)),
                BarcodeType::EAN_13 => (new TypeEan13())->getBarcode($code),
                BarcodeType::EAN_8 => (new TypeEan8())->getBarcode($code),
                BarcodeType::UPC_A => (new TypeUpcA())->getBarcode($code),
                BarcodeType::ITF => (new TypeInterleaved25())->getBarcode($code),
            };
        } catch (Throwable $e) {
            throw new InvalidBarcodeContentException($e->getMessage(), 0, $e);
        }
    }

    /** @throws InvalidBarcodeContentException */
    private static function validate(BarcodeType $type, string $code): void
    {
        match ($type) {
            BarcodeType::EAN_13 => self::digitsOnly($code, [12, 13], 'EAN-13'),
            BarcodeType::EAN_8 => self::digitsOnly($code, [7, 8], 'EAN-8'),
            BarcodeType::UPC_A => self::digitsOnly($code, [11, 12], 'UPC-A'),
            BarcodeType::ITF => self::digitsOnly($code, null, 'ITF'),
            BarcodeType::CODE_39 => self::code39($code),
            BarcodeType::CODE_128 => self::code128($code),
        };
    }

    /**
     * @param list<int>|null $lengths 允许的长度；null 表示只校验为纯数字
     *
     * @throws InvalidBarcodeContentException
     */
    private static function digitsOnly(string $code, ?array $lengths, string $label): void
    {
        if (!preg_match('/^\d+$/', $code)) {
            throw new InvalidBarcodeContentException(sprintf('%s content must be numeric', $label));
        }
        if ($lengths !== null && !in_array(strlen($code), $lengths, true)) {
            throw new InvalidBarcodeContentException(sprintf('%s content length is invalid', $label));
        }
    }

    /** @throws InvalidBarcodeContentException */
    private static function code39(string $code): void
    {
        if (!preg_match('/^[0-9A-Z\-. $\/+%]+$/', strtoupper($code))) {
            throw new InvalidBarcodeContentException('CODE 39 content has invalid characters');
        }
    }

    /**
     * CODE 128 为可变长度 ASCII 制式，无额外字符集/长度约束。
     *
     * 空内容已在 {@see encode()} 入口统一拦截并抛 InvalidBarcodeContentException，
     * 具体编码合法性交由 picqer TypeCode128 处理，此处无需预检。
     */
    private static function code128(string $code): void
    {
    }
}
