<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Barcode;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Barcode\BarcodeManager;
use Swoolefy\Library\Barcode\BarcodeType;
use Swoolefy\Library\Barcode\Exception\InvalidBarcodeContentException;

final class BarcodeModuleTest extends TestCase
{
    public function testCode128Svg(): void
    {
        $result = (new BarcodeManager([]))
            ->make('081231723897')
            ->type(BarcodeType::CODE_128)
            ->svg();

        self::assertStringContainsString('<svg', $result->binary);
        self::assertSame('image/svg+xml', $result->mime);
    }

    /**
     * 回归：code39 正则曾因未转义分隔符 / 导致任意输入均被判非法。
     */
    public function testCode39SvgAcceptsValidCharset(): void
    {
        $result = (new BarcodeManager([]))
            ->make('AB-12 $99/OK')
            ->type(BarcodeType::CODE_39)
            ->svg();

        self::assertStringContainsString('<svg', $result->binary);
    }

    public function testCode39RejectsInvalidCharset(): void
    {
        $this->expectException(InvalidBarcodeContentException::class);
        (new BarcodeManager([]))
            ->make('BAD_CHAR')
            ->type(BarcodeType::CODE_39)
            ->svg();
    }

    public function testSvgShowTextDefaultsOn(): void
    {
        $binary = (new BarcodeManager([]))
            ->make('081231723897')
            ->type(BarcodeType::EAN_13)
            ->svg()
            ->binary;

        self::assertStringContainsString('<text', $binary);
        self::assertStringContainsString('081231723897</text>', $binary);
    }

    public function testSvgShowTextDisabledHasNoText(): void
    {
        $binary = (new BarcodeManager([]))
            ->make('ABC')
            ->type(BarcodeType::CODE_39)
            ->showText(false)
            ->svg()
            ->binary;

        self::assertStringNotContainsString('<text', $binary);
    }

    public function testEan13InvalidLength(): void
    {
        $this->expectException(InvalidBarcodeContentException::class);
        (new BarcodeManager([]))
            ->make('123')
            ->type(BarcodeType::EAN_13)
            ->svg();
    }

    /**
     * @group barcode
     */
    public function testPngOutput(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            self::markTestSkipped('gd or imagick required');
        }

        $png = (new BarcodeManager([]))
            ->make('081231723897')
            ->png();

        self::assertSame("\x89PNG", substr($png->binary, 0, 4));
    }
}
