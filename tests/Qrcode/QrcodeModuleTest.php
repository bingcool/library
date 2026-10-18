<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Qrcode;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Qrcode\Exception\InvalidQrcodeContentException;
use Swoolefy\Library\Qrcode\Exception\QrcodeException;
use Swoolefy\Library\Qrcode\QrcodeManager;
use Swoolefy\Library\Qrcode\Support\ErrorCorrectionMapper;
use Swoolefy\Library\Qrcode\Support\WifiStringBuilder;

final class QrcodeModuleTest extends TestCase
{
    public function testErrorCorrectionMapper(): void
    {
        self::assertSame('M', (string) ErrorCorrectionMapper::map('m'));
    }

    public function testWifiEscapesSpecialCharacters(): void
    {
        $payload = WifiStringBuilder::build('Cafe;X', 'p\\ass', 'WPA');
        self::assertStringContainsString('WIFI:T:WPA;', $payload);
        self::assertStringContainsString('Cafe\\;X', $payload);
    }

    public function testEmptyContentRejected(): void
    {
        $this->expectException(InvalidQrcodeContentException::class);
        (new QrcodeManager([]))->make('   ')->svg();
    }

    public function testSvgOutputWithoutGd(): void
    {
        $svg = (new QrcodeManager([]))
            ->url('https://example.com')
            ->size(120)
            ->svg();

        self::assertStringContainsString('<svg', $svg->binary);
        self::assertSame('image/svg+xml', $svg->mime);
    }

    /**
     * @group qrcode
     */
    public function testPngMagicBytes(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd required for PNG QR');
        }

        $png = (new QrcodeManager([]))->make('hello')->png();
        self::assertSame("\x89PNG", substr($png->binary, 0, 4));
    }

    /**
     * @group qrcode
     */
    public function testWithLogoRequiresImageComponent(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd required');
        }

        $logo = $this->tempPng(8, 8);
        $this->expectException(QrcodeException::class);
        (new QrcodeManager([]))
            ->make('logo-test')
            ->errorCorrection('H')
            ->withLogo($logo, 0.15)
            ->png();
    }

    /**
     * @group qrcode
     */
    public function testWithLogoChangesPngBytes(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd required');
        }

        $logo = $this->tempPng(12, 12);
        $plain = (new QrcodeManager([]))->make('logo-test')->errorCorrection('H')->png()->binary;
        $withLogo = (new QrcodeManager([], null, new ImageManager(['driver' => 'gd'])))
            ->make('logo-test')
            ->errorCorrection('H')
            ->withLogo($logo, 0.15)
            ->png()
            ->binary;

        self::assertNotSame($plain, $withLogo);
    }

    private function tempPng(int $w, int $h): string
    {
        $path = sys_get_temp_dir() . '/qr-logo-' . bin2hex(random_bytes(4)) . '.png';
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 0, 0));
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
