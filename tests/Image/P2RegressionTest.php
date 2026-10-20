<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Image;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager as InterventionImageManager;
use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Image\Config\ImageConfig;
use Swoolefy\Library\Image\Config\ImagePresetConfig;
use Swoolefy\Library\Image\Config\ImagePresetsConfig;
use Swoolefy\Library\Image\Exception\ImageTooLargeException;
use Swoolefy\Library\Image\Exception\InvalidConfigException;
use Swoolefy\Library\Image\Exception\InvalidImageException;
use Swoolefy\Library\Image\Exception\UnsupportedFormatException;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Image\Support\FormatNegotiator;
use Swoolefy\Library\Image\Support\SizeCalculator;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\Watermark\ImageWatermark;
use Swoolefy\Library\Image\Watermark\TextWatermark;

/**
 * P2 健壮性回归测试。
 *
 * 覆盖：driver 严格校验、运行时格式能力、quality/thumbnail 参数、透明源默认 PNG、
 * base_path/allowed_roots、overlay 复用、SizeCalculator 一致性、preset 名称类型、
 * 流 rewind、EXIF 只校正一次、GIF strip、encodePngBinary 统一管线。
 */
final class P2RegressionTest extends TestCase
{
    private const LOGO_SIZE = 40;

    private static string $root = '';

    private static string $logoDir = '';

    private static string $outsideDir = '';

    private static string $logoPath = '';

    private static string $outsideLogoPath = '';

    private static string $fontPath = '';

    private ImageManager $manager;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        self::$root = sys_get_temp_dir() . '/image-p2-' . bin2hex(random_bytes(4));
        self::$logoDir = self::$root . '/logo';
        self::$outsideDir = self::$root . '/outside';
        mkdir(self::$logoDir, 0777, true);
        mkdir(self::$outsideDir, 0777, true);

        self::$logoPath = self::$logoDir . '/logo.png';
        self::$outsideLogoPath = self::$outsideDir . '/logo.png';
        self::writeRedPng(self::$logoPath);
        self::writeRedPng(self::$outsideLogoPath);

        self::$fontPath = self::$logoDir . '/captcha.ttf';
        copy(dirname(__DIR__, 2) . '/src/Captcha/Font/captcha0.ttf', self::$fontPath);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$logoPath, self::$outsideLogoPath, self::$fontPath] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }

        foreach ([self::$logoDir, self::$outsideDir, self::$root] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd extension required');
        }

        $this->manager = new ImageManager(['driver' => 'gd']);
    }

    public function testInvalidDriverIsRejectedAtConstruction(): void
    {
        try {
            new ImageManager(['driver' => 'imagik']);
            self::fail('unknown driver should be rejected');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('imagik', $e->getMessage());
        }

        try {
            FormatNegotiator::supported('imagik');
            self::fail('unknown driver should be rejected by FormatNegotiator');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('imagik', $e->getMessage());
        }
    }

    public function testRuntimeFormatCapabilitiesAreFiltered(): void
    {
        $supported = FormatNegotiator::supported('gd');
        self::assertContains('png', $supported);
        self::assertNotContains('avif', $supported, 'GD 静态白名单不含 avif');
        self::assertTrue(FormatNegotiator::runtimeSupports('gd', 'png'));
        self::assertFalse(FormatNegotiator::runtimeSupports('gd', 'tiff'));

        $webp = (bool) (gd_info()['WebP Support'] ?? false);
        self::assertSame($webp, in_array('webp', $supported, true), 'WebP 能力必须来自 gd_info()');

        if (extension_loaded('imagick')) {
            self::assertTrue(FormatNegotiator::runtimeSupports('imagick', 'jpeg'));
        }
    }

    public function testQualityOutOfRangeIsRejectedAtEntry(): void
    {
        foreach ([101, -1, 200] as $quality) {
            try {
                $this->manager->create(10, 10)->encode(new EncodeOptions(quality: $quality));
                self::fail(sprintf('quality %d should be rejected', $quality));
            } catch (InvalidImageException $e) {
                self::assertStringContainsString('quality', $e->getMessage());
            }
        }
    }

    public function testThumbnailRequiresPositiveDimension(): void
    {
        try {
            $this->manager->create(100, 50)->thumbnail(0, 0);
            self::fail('thumbnail(0, 0) should be rejected');
        } catch (InvalidImageException $e) {
            self::assertStringContainsString('thumbnail', $e->getMessage());
        }

        $byHeight = $this->manager->create(100, 50)->thumbnail(0, 25)->size();
        self::assertSame(50, $byHeight->width);
        self::assertSame(25, $byHeight->height);

        $byWidth = $this->manager->create(100, 50)->thumbnail(25, -1)->size();
        self::assertSame(25, $byWidth->width);
        self::assertLessThanOrEqual(25, $byWidth->height);
    }

    public function testTransparentPngDefaultsToPng(): void
    {
        $path = $this->transparentPng(8, 8);

        try {
            $result = $this->manager->open($path)->encode();
            self::assertSame("\x89PNG", substr($result->binary, 0, 4));
            self::assertSame('image/png', $result->mime);
            self::assertSame('png', $result->extension);

            $image = imagecreatefromstring($result->binary);
            self::assertNotFalse($image);
            $color = imagecolorsforindex($image, imagecolorat($image, 0, 0));
            imagedestroy($image);
            self::assertSame(127, $color['alpha'], '默认 PNG 必须保留透明通道');
        } finally {
            unlink($path);
        }
    }

    public function testOpaqueCreateStillDefaultsToJpeg(): void
    {
        $result = $this->manager->create(8, 8, '#336699')->encode();

        self::assertSame("\xff\xd8", substr($result->binary, 0, 2));
        self::assertSame('image/jpeg', $result->mime);
    }

    public function testExplicitJpegOverridesTransparentDefault(): void
    {
        $path = $this->transparentPng(8, 8);

        try {
            $result = $this->manager->open($path)->encode(new EncodeOptions(format: 'jpeg'));
            self::assertSame('image/jpeg', $result->mime);
        } finally {
            unlink($path);
        }
    }

    public function testRelativeWatermarkPathUsesConfiguredBasePath(): void
    {
        $config = ImageConfig::fromArray([
            'base_path' => self::$logoDir,
            'watermark' => [
                'image' => ['path' => 'logo.png'],
            ],
        ]);

        $typed = $config->watermarkImage();
        self::assertSame(realpath(self::$logoDir), $typed->basePath);
        self::assertSame([realpath(self::$logoDir)], $typed->allowedRoots);

        $watermark = ImageWatermark::fromWatermarkConfig($typed);
        self::assertSame(realpath(self::$logoPath), $watermark->path);
        self::assertSame(60, $this->manager->create(60, 60)->watermarkImage($watermark)->size()->width);
    }

    public function testRelativeTextFontUsesConfiguredBasePath(): void
    {
        $config = ImageConfig::fromArray([
            'base_path' => self::$logoDir,
            'watermark' => [
                'text' => ['content' => 'A', 'font' => 'captcha.ttf'],
            ],
        ]);

        $watermark = TextWatermark::fromConfig($config);
        self::assertSame(realpath(self::$fontPath), $watermark->font);
        self::assertSame(120, $this->manager->create(120, 60, '#000')->watermarkText($watermark)->size()->width);
    }

    public function testWatermarkOutsideAllowedRootsIsRejected(): void
    {
        $config = ImageConfig::fromArray([
            'base_path' => self::$logoDir,
            'watermark' => [
                'image' => ['path' => self::$outsideLogoPath],
            ],
        ]);

        $this->expectException(InvalidImageException::class);
        ImageWatermark::fromConfig($config);
    }

    public function testExplicitAllowedRootsPermitOutsidePath(): void
    {
        $config = ImageConfig::fromArray([
            'base_path' => self::$logoDir,
            'watermark' => [
                'image' => [
                    'path' => self::$outsideLogoPath,
                    'allowed_roots' => [self::$outsideDir],
                ],
            ],
        ]);

        $watermark = ImageWatermark::fromConfig($config);
        self::assertSame(realpath(self::$outsideLogoPath), $watermark->path);
    }

    public function testDirectWatermarkWithoutRootsKeepsBackwardCompatibility(): void
    {
        $watermark = new ImageWatermark(self::$outsideLogoPath);

        self::assertSame(realpath(self::$outsideLogoPath), $watermark->path);
        self::assertSame([], $watermark->allowedRoots);
    }

    public function testOverlayCenterImageReusesDecodedOverlayWithoutMutation(): void
    {
        $intervention = new InterventionImageManager(new GdDriver());
        $overlay = $intervention->decodePath(self::$logoPath);

        $small = $this->manager->create(100, 100, '#ffffff')
            ->overlayCenterImage($overlay, 20, 20)
            ->toBinary(new EncodeOptions(format: 'png'));
        self::assertSame([40, 40, 59, 59], $this->redBox($small));
        self::assertSame(self::LOGO_SIZE, $overlay->width(), '复用 overlay 时不得被 cover() 修改');

        $large = $this->manager->create(100, 100, '#ffffff')
            ->overlayCenterImage($overlay, 40, 40)
            ->toBinary(new EncodeOptions(format: 'png'));
        self::assertSame([30, 30, 69, 69], $this->redBox($large));
    }

    public function testSizeCalculatorMatchesEditorContain(): void
    {
        $cases = [
            [1000, 500, 400, 400],
            [100, 50, 400, 400],
            [2000, 1000, 750, 0],
            [100, 50, 0, 25],
        ];

        foreach ($cases as [$sourceW, $sourceH, $maxW, $maxH]) {
            $expected = SizeCalculator::contain($sourceW, $sourceH, $maxW, $maxH);
            $size = $this->manager->create($sourceW, $sourceH)
                ->resize($maxW > 0 ? $maxW : null, $maxH > 0 ? $maxH : null, 'contain')
                ->size();

            self::assertSame($expected['width'], $size->width);
            self::assertSame($expected['height'], $size->height);
        }
    }

    public function testPresetNamesAreAlwaysStrings(): void
    {
        $direct = new ImagePresetsConfig([
            0 => ImagePresetConfig::fromArray(['size' => [10, 10]]),
        ]);
        self::assertSame(['0'], $direct->names());

        $fromArray = ImagePresetsConfig::fromArray(['0' => ['size' => [10, 10]]]);
        self::assertSame(['0'], $fromArray->names());
    }

    public function testStreamAtNonZeroPositionIsRewound(): void
    {
        $path = $this->tempPng(40, 30);
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);
        fseek($stream, 10);

        try {
            $size = $this->manager->open($stream)->size();
            self::assertSame(40, $size->width);
            self::assertSame(30, $size->height);
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testExifOrientationIsAppliedOnlyOnce(): void
    {
        if (!function_exists('exif_read_data')) {
            self::markTestSkipped('ext-exif is required for EXIF auto-orientation');
        }

        $path = $this->jpegWithOrientation6();

        try {
            $size = $this->manager->open($path)->size();
            self::assertSame(20, $size->width, 'orientation=6 应只旋转一次');
            self::assertSame(40, $size->height, 'orientation=6 应只旋转一次');
        } finally {
            unlink($path);
        }
    }

    public function testGifStripIsRejected(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->manager->create(10, 10)->encode(new EncodeOptions(format: 'gif', strip: true));
    }

    public function testEncodePngBinaryUsesUnifiedPipeline(): void
    {
        $png = $this->manager->create(16, 16)->encodePngBinary();
        self::assertSame("\x89PNG", substr($png, 0, 4));

        $strict = new ImageManager([
            'driver' => 'gd',
            'encode' => ['format' => 'jpeg', 'max_bytes' => 10],
        ]);

        $this->expectException(ImageTooLargeException::class);
        $strict->create(20, 20)->encodePngBinary();
    }

    private static function writeRedPng(string $path): void
    {
        $image = imagecreatetruecolor(self::LOGO_SIZE, self::LOGO_SIZE);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function transparentPng(int $w, int $h): string
    {
        $path = sys_get_temp_dir() . '/img-p2-alpha-' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function tempPng(int $w, int $h): string
    {
        $path = sys_get_temp_dir() . '/img-p2-' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor($w, $h);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /** 生成带 EXIF Orientation=6 的 40×20 JPEG，用于验证只做一次方向校正 */
    private function jpegWithOrientation6(): string
    {
        $path = sys_get_temp_dir() . '/img-p2-orient-' . bin2hex(random_bytes(4)) . '.jpg';
        $image = imagecreatetruecolor(40, 20);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        $jpeg = (string) file_get_contents($path);
        $app1 = "\xFF\xE1\x00\x22"
            . "Exif\x00\x00"
            . "II\x2A\x00\x08\x00\x00\x00"
            . "\x01\x00"
            . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"
            . "\x00\x00\x00\x00";

        file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));

        return $path;
    }

    /**
     * @return array{int, int, int, int} [minX, minY, maxX, maxY]
     */
    private function redBox(string $binary): array
    {
        $image = imagecreatefromstring($binary);
        self::assertNotFalse($image);

        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = -1;
        for ($x = 0; $x < imagesx($image); $x++) {
            for ($y = 0; $y < imagesy($image); $y++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['red'] < 200 || $color['green'] > 80 || $color['blue'] > 80) {
                    continue;
                }

                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
        imagedestroy($image);

        self::assertGreaterThanOrEqual(0, $maxX, '输出图中找不到 overlay 像素');

        return [$minX, $minY, $maxX, $maxY];
    }
}
