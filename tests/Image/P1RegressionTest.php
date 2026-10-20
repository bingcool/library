<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Image;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Image\Config\ImagePresetConfig;
use Swoolefy\Library\Image\Exception\ImageTooLargeException;
use Swoolefy\Library\Image\Exception\InvalidImageException;
use Swoolefy\Library\Image\Exception\UnsupportedFormatException;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Image\Support\FormatNegotiator;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\Watermark\ImageWatermark;

/**
 * P1 正确性与安全的回归测试。
 *
 * 覆盖：
 * - P1-1 contain 等比、只缩不放、不补白
 * - P1-2/P1-8 EncodeOptions 三级逐字段合并与 max_bytes
 * - P1-3 连续 applyPreset 不残留上一个 encode
 * - P1-4 bmp/tiff 无 encoder 时直接拒绝
 * - P1-5 二进制/流解码前像素预检、水印/overlay 受检
 * - P1-6 resize 参数矩阵
 * - P1-7 目标尺寸 max_pixels 校验
 * - P1-9 Intervention 异常包装为模块异常
 */
final class P1RegressionTest extends TestCase
{
    private const LOGO_SIZE = 150;

    private static string $logoPath = '';

    private ImageManager $manager;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $path = sys_get_temp_dir() . '/image-p1-logo-' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor(self::LOGO_SIZE, self::LOGO_SIZE);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        imagepng($image, $path);
        imagedestroy($image);

        self::$logoPath = $path;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$logoPath !== '' && is_file(self::$logoPath)) {
            unlink(self::$logoPath);
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd extension required');
        }

        $this->manager = new ImageManager(['driver' => 'gd']);
    }

    public function testContainScalesDownWithoutPaddingOrUpscaling(): void
    {
        $big = $this->manager->create(1000, 500)->resize(400, 400, 'contain')->size();
        self::assertSame(400, $big->width);
        self::assertSame(200, $big->height);

        $small = $this->manager->create(100, 50)->resize(400, 400, 'contain')->size();
        self::assertSame(100, $small->width);
        self::assertSame(50, $small->height);

        $tiny = $this->manager->create(20, 10)->resize(400, 400, 'contain')->size();
        self::assertSame(20, $tiny->width);
        self::assertSame(10, $tiny->height);
    }

    public function testContainSingleDimensionKeepsAspectRatio(): void
    {
        $size = $this->manager->create(1000, 500)->resize(400, null, 'contain')->size();

        self::assertSame(400, $size->width);
        self::assertSame(200, $size->height);
    }

    public function testScaleSingleDimensionKeepsAspectRatio(): void
    {
        $size = $this->manager->create(100, 50)->resize(20, null, 'scale')->size();
        self::assertSame(20, $size->width);
        self::assertSame(10, $size->height);

        $byHeight = $this->manager->create(100, 50)->resize(null, 25, 'stretch')->size();
        self::assertSame(50, $byHeight->width);
        self::assertSame(25, $byHeight->height);
    }

    public function testResizeRejectsInvalidParameterCombinations(): void
    {
        $cases = [
            [0, 0, 'contain'],
            [null, null, 'scale'],
            [20, null, 'cover'],
            [-5, -5, 'fill'],
            [10, 10, 'unknown'],
        ];

        foreach ($cases as [$w, $h, $mode]) {
            try {
                $this->manager->create(100, 50)->resize($w, $h, $mode);
                self::fail(sprintf('resize(%s, %s, %s) should be rejected', var_export($w, true), var_export($h, true), $mode));
            } catch (InvalidImageException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testEncodeOptionsMergeIsFieldLevel(): void
    {
        $merged = EncodeOptions::merge(
            ['format' => 'jpeg', 'quality' => 70, 'progressive' => true, 'strip' => false, 'max_bytes' => 1000],
            new EncodeOptions(format: 'png'),
            new EncodeOptions(quality: 90),
        );

        self::assertSame('png', $merged->format);
        self::assertSame(90, $merged->quality);
        self::assertTrue($merged->progressive);
        self::assertFalse($merged->strip);
        self::assertSame(1000, $merged->maxBytes);
    }

    public function testEncodeMergesGlobalPresetAndRuntimeOptions(): void
    {
        $manager = new ImageManager([
            'driver' => 'gd',
            'encode' => ['format' => 'jpeg', 'quality' => 70],
        ]);
        $preset = ImagePresetConfig::fromArray([
            'mode' => 'contain',
            'size' => [10, 10],
            'encode' => ['format' => 'png'],
        ]);

        $result = $manager->create(20, 20)
            ->applyPresetConfig($preset)
            ->encode(new EncodeOptions(quality: 90));

        self::assertSame('image/png', $result->mime);
        self::assertSame('png', $result->extension);
        self::assertSame("\x89PNG", substr($result->binary, 0, 4));
    }

    public function testApplyPresetResetsPreviousEncode(): void
    {
        $manager = new ImageManager([
            'driver' => 'gd',
            'encode' => ['format' => 'jpeg'],
        ]);
        $withWebp = ImagePresetConfig::fromArray([
            'size' => [10, 10],
            'encode' => ['format' => 'webp'],
        ]);
        $withoutEncode = ImagePresetConfig::fromArray(['size' => [10, 10]]);

        $result = $manager->create(20, 20)
            ->applyPresetConfig($withWebp)
            ->applyPresetConfig($withoutEncode)
            ->encode();

        self::assertSame('image/jpeg', $result->mime);
        self::assertSame('jpg', $result->extension);
    }

    public function testPresetMaxBytesIsHonored(): void
    {
        $preset = ImagePresetConfig::fromArray([
            'size' => [10, 10],
            'encode' => ['max_bytes' => 1],
        ]);

        $this->expectException(ImageTooLargeException::class);
        $this->manager->create(20, 20)->applyPresetConfig($preset)->encode();
    }

    public function testFormatsWithoutEncoderAreRejected(): void
    {
        self::assertNotContains('bmp', FormatNegotiator::supported('imagick'));
        self::assertNotContains('tiff', FormatNegotiator::supported('imagick'));

        try {
            FormatNegotiator::assertSupported('imagick', 'bmp');
            self::fail('bmp should not be supported without an encoder');
        } catch (UnsupportedFormatException $e) {
            self::assertStringContainsString('bmp', $e->getMessage());
        }

        $this->expectException(UnsupportedFormatException::class);
        $this->manager->create(10, 10)->encode(new EncodeOptions(format: 'tiff'));
    }

    public function testEncodedMetadataMatchesActualFormat(): void
    {
        $png = $this->manager->create(16, 16)->encode(new EncodeOptions(format: 'png'));
        self::assertSame("\x89PNG", substr($png->binary, 0, 4));
        self::assertSame('image/png', $png->mime);
        self::assertSame('png', $png->extension);

        $jpeg = $this->manager->create(16, 16)->encode(new EncodeOptions(format: 'jpg'));
        self::assertSame("\xff\xd8", substr($jpeg->binary, 0, 2));
        self::assertSame('image/jpeg', $jpeg->mime);
        self::assertSame('jpg', $jpeg->extension);
    }

    public function testBinaryIsCheckedBeforeDecode(): void
    {
        $png = $this->manager->create(100, 100)->encode(new EncodeOptions(format: 'png'))->binary;
        $strict = new ImageManager(['driver' => 'gd', 'max_pixels' => 50]);

        $this->expectException(ImageTooLargeException::class);
        $strict->open($png);
    }

    public function testSeekableStreamIsCheckedAndRestored(): void
    {
        $path = $this->tempPng(100, 100);
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);

        try {
            $editor = $this->manager->open($stream);
            self::assertSame(100, $editor->size()->width);
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testNonSeekableStreamIsBufferedAndDecoded(): void
    {
        if (!function_exists('stream_socket_pair')) {
            self::markTestSkipped('stream_socket_pair required');
        }

        $png = $this->manager->create(40, 30)->encode(new EncodeOptions(format: 'png'))->binary;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$reader, $writer] = $pair;

        fwrite($writer, $png);
        stream_socket_shutdown($writer, STREAM_SHUT_WR);

        try {
            $editor = $this->manager->open($reader);
            self::assertSame(40, $editor->size()->width);
            self::assertSame(30, $editor->size()->height);
        } finally {
            fclose($reader);
            fclose($writer);
        }
    }

    public function testStreamIsCheckedBeforeDecode(): void
    {
        $path = $this->tempPng(100, 100);
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);
        $strict = new ImageManager(['driver' => 'gd', 'max_pixels' => 50]);

        try {
            $this->expectException(ImageTooLargeException::class);
            $strict->open($stream);
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testWatermarkLogoRespectsMaxPixels(): void
    {
        // 画布 100×100=10000 通过，logo 150×150=22500 超限
        $strict = new ImageManager(['driver' => 'gd', 'max_pixels' => 20000]);
        $mark = new ImageWatermark(self::$logoPath, 'bottom-right', 4, 4, 1.0);

        $this->expectException(ImageTooLargeException::class);
        $strict->create(100, 100)->watermarkImage($mark);
    }

    public function testOverlayRespectsMaxPixels(): void
    {
        $strict = new ImageManager(['driver' => 'gd', 'max_pixels' => 20000]);

        $this->expectException(ImageTooLargeException::class);
        $strict->create(100, 100)->overlayCenter(self::$logoPath, 20, 20);
    }

    public function testTargetSizeRespectsMaxPixels(): void
    {
        $strict = new ImageManager(['driver' => 'gd', 'max_pixels' => 1_000_000]);

        $operations = [
            'scale' => static fn (ImageManager $m) => $m->create(100, 100)->resize(10000, 10000, 'scale'),
            'cover' => static fn (ImageManager $m) => $m->create(100, 100)->resize(10000, 100, 'cover'),
            'crop' => static fn (ImageManager $m) => $m->create(100, 100)->crop(0, 0, 2000, 2000),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation($strict);
                self::fail(sprintf('%s target should respect max_pixels', $name));
            } catch (ImageTooLargeException $e) {
                self::assertStringContainsString('max_pixels', $e->getMessage());
            }
        }
    }

    public function testQualityOutOfRangeIsRejectedBeforeEncoder(): void
    {
        // P2-2 将越界 quality 提前到 encode() 入口校验，不再依赖 encoder 抛原始异常
        try {
            $this->manager->create(10, 10)->encode(new EncodeOptions(format: 'jpeg', quality: 200));
            self::fail('quality out of range should be rejected');
        } catch (InvalidImageException $e) {
            self::assertStringContainsString('quality', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testCanvasColorExceptionIsWrapped(): void
    {
        try {
            $this->manager->create(10, 10, 'not-a-color');
            self::fail('invalid background should be rejected');
        } catch (InvalidImageException $e) {
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testWatermarkPositionExceptionIsWrapped(): void
    {
        $mark = new ImageWatermark(self::$logoPath, 'not-a-position');

        try {
            $this->manager->create(60, 60)->watermarkImage($mark);
            self::fail('invalid position should be rejected');
        } catch (InvalidImageException $e) {
            self::assertNotNull($e->getPrevious());
        }
    }

    private function tempPng(int $w, int $h): string
    {
        $path = sys_get_temp_dir() . '/img-p1-' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor($w, $h);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
