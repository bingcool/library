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
