<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Image;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Exception\ImageTooLargeException;
use Swoolefy\Library\Image\Exception\InvalidImageException;
use Swoolefy\Library\Image\Exception\UnsupportedFormatException;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Image\Support\FormatNegotiator;
use Swoolefy\Library\Image\Support\SizeCalculator;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;

final class ImageModuleTest extends TestCase
{
    public function testSizeCalculatorContainAndCover(): void
    {
        $contain = SizeCalculator::contain(2000, 1000, 750, 0);
        self::assertSame(750, $contain['width']);
        self::assertSame(375, $contain['height']);

        $cover = SizeCalculator::cover(2000, 1000, 200, 200);
        self::assertSame(400, $cover['width']);
        self::assertSame(200, $cover['height']);
    }

    public function testFormatNegotiatorRejectsAvifOnGd(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        FormatNegotiator::assertSupported('gd', 'avif');
    }

    public function testConfigFromArrayStripsOuterKey(): void
    {
        $config = ImageConfig::fromArray(['image' => ['driver' => 'gd', 'max_pixels' => 100]]);
        self::assertSame('gd', $config->driver());
        self::assertSame(100, $config->maxPixels());
    }

    public function testWatermarkConfigTypedAccessAndOverrides(): void
    {
        $config = ImageConfig::fromArray([
            'watermark' => [
                'image' => [
                    'path' => '/tmp/logo.png',
                    'position' => 'center',
                    'offset_x' => 8,
                    'opacity' => 0.5,
                ],
                'text' => [
                    'content' => 'demo',
                    'font' => '/tmp/font.ttf',
                    'size' => 24,
                ],
            ],
        ]);

        $imageWm = $config->watermarkImage();
        self::assertSame('/tmp/logo.png', $imageWm->path);
        self::assertSame('center', $imageWm->position);
        self::assertSame(8, $imageWm->offsetX);

        $overridden = $imageWm->withOverrides(['opacity' => 0.9, 'offset_y' => 4]);
        self::assertSame(0.9, $overridden->opacity);
        self::assertSame(4, $overridden->offsetY);
        self::assertSame(8, $overridden->offsetX);

        $textWm = $config->watermarkText()->withOverrides(['content' => '内部']);
        self::assertSame('内部', $textWm->content);
        self::assertSame(24.0, $textWm->size);
    }

    public function testPresetConfigTypedAccess(): void
    {
        $config = ImageConfig::fromArray([
            'presets' => [
                'thumb' => ['mode' => 'cover', 'size' => [200, 200]],
                'list' => ['mode' => 'contain', 'size' => [750, 0]],
                'avatar' => [
                    'mode' => 'cover',
                    'size' => [128, 128],
                    'encode' => ['format' => 'webp', 'quality' => 90],
                ],
            ],
        ]);

        $all = $config->presets();
        self::assertSame(['thumb', 'list', 'avatar'], $all->names());

        $list = $config->preset('list');
        self::assertSame('contain', $list->mode);
        self::assertSame(750, $list->width);
        self::assertSame(0, $list->height);
        self::assertTrue($list->hasResize());
        self::assertNull($list->encodeOptions());

        $avatar = $config->preset('avatar');
        self::assertNotNull($avatar->encodeOptions());
        self::assertSame('webp', $avatar->encodeOptions()->format);
        self::assertSame(90, $avatar->encodeOptions()->quality);

        $this->expectException(InvalidImageException::class);
        $config->preset('missing');
    }

    /**
     * @group image
     */
    public function testCreateAndEncodePng(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd extension required');
        }

        $result = (new ImageManager(['driver' => 'gd']))
            ->create(32, 16, '#336699')
            ->encode(new EncodeOptions(format: 'png'));

        self::assertSame("\x89PNG", substr($result->binary, 0, 4));
        self::assertSame('image/png', $result->mime);
        self::assertSame(32, $result->size->width);
    }

    /**
     * @group image
     */
    public function testMaxPixelsRejectedBeforeDecode(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd extension required');
        }

        $path = $this->tempPng(10, 10);
        $manager = new ImageManager(['driver' => 'gd', 'max_pixels' => 50]);

        $this->expectException(ImageTooLargeException::class);
        $manager->open($path);
    }

    public function testInvalidBinaryThrows(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd extension required');
        }

        $this->expectException(InvalidImageException::class);
        (new ImageManager(['driver' => 'gd']))->open('not-an-image');
    }

    private function tempPng(int $w, int $h): string
    {
        $path = sys_get_temp_dir() . '/img-test-' . bin2hex(random_bytes(4)) . '.png';
        $img = imagecreatetruecolor($w, $h);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
