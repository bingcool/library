<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Image;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Image\Exception\InvalidImageException;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Image\ValueObject\EncodeOptions;
use Swoolefy\Library\Image\Watermark\ImageWatermark;
use Swoolefy\Library\Image\Watermark\TextWatermark;
use Swoolefy\Library\Qrcode\QrcodeManager;

/**
 * P0 水印缺陷的像素级回归测试。
 *
 * 断言直接扫描输出 PNG 的像素，而不是只验证「不抛异常」：
 * - P0-1 九宫格定位：insert 的 x/y 是相对锚点偏移
 * - P0-2 overlayCenter：居中且默认不透明
 * - P0-3 watermarkImage：opacity 不被反转
 * - P0-4 watermarkText：alpha 走 0–255，颜色支持缩写与命名色
 */
final class WatermarkPixelTest extends TestCase
{
    private const LOGO_SIZE = 40;

    private const OFFSET = 16;

    private static string $logoPath = '';

    private ImageManager $manager;

    private string $font = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $path = sys_get_temp_dir() . '/image-wm-logo-' . bin2hex(random_bytes(4)) . '.png';
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
        $this->font = dirname(__DIR__, 2) . '/src/Captcha/Font/captcha0.ttf';
    }

    public function testImageWatermarkNineGridPositions(): void
    {
        $canvasW = 200;
        $canvasH = 120;
        $size = self::LOGO_SIZE;
        $offset = self::OFFSET;

        $expected = [
            'top-left' => [$offset, $offset],
            'top' => [intdiv($canvasW - $size, 2), $offset],
            'top-right' => [$canvasW - $offset - $size, $offset],
            'left' => [$offset, intdiv($canvasH - $size, 2)],
            'center' => [intdiv($canvasW - $size, 2), intdiv($canvasH - $size, 2)],
            'right' => [$canvasW - $offset - $size, intdiv($canvasH - $size, 2)],
            'bottom-left' => [$offset, $canvasH - $offset - $size],
            'bottom' => [intdiv($canvasW - $size, 2), $canvasH - $offset - $size],
            'bottom-right' => [$canvasW - $offset - $size, $canvasH - $offset - $size],
        ];

        foreach ($expected as $position => [$x, $y]) {
            $binary = $this->manager->create($canvasW, $canvasH, '#ffffff')
                ->watermarkImage(new ImageWatermark(self::$logoPath, $position, $offset, $offset, 1.0))
                ->toBinary(new EncodeOptions(format: 'png'));

            self::assertSame(
                [$x, $y, $x + $size - 1, $y + $size - 1],
                $this->redBox($binary),
                sprintf('九宫格位置 %s 的 logo 包围盒不正确', $position),
            );
        }
    }

    public function testImageWatermarkOpacityIsNotInverted(): void
    {
        $opaque = $this->rgbAt($this->watermarkedImage(1.0), 30, 30);
        self::assertSame([255, 0, 0], $opaque, 'opacity=1.0 必须完全不透明');

        [, $green] = $this->rgbAt($this->watermarkedImage(0.5), 30, 30);
        self::assertGreaterThanOrEqual(120, $green, 'opacity=0.5 应混合出约 50% 白底');
        self::assertLessThanOrEqual(136, $green, 'opacity=0.5 应混合出约 50% 白底');

        self::assertSame(
            [255, 255, 255],
            $this->rgbAt($this->watermarkedImage(0.0), 30, 30),
            'opacity=0.0 必须完全透明',
        );
    }

    public function testOverlayCenterIsCenteredAndOpaqueByDefault(): void
    {
        $binary = $this->manager->create(200, 200, '#ffffff')
            ->overlayCenter(self::$logoPath, self::LOGO_SIZE, self::LOGO_SIZE)
            ->toBinary(new EncodeOptions(format: 'png'));

        self::assertSame([80, 80, 119, 119], $this->redBox($binary), 'overlay 必须居中且默认可见');
    }

    public function testOverlayCenterRespectsOpacity(): void
    {
        $binary = $this->manager->create(200, 200, '#ffffff')
            ->overlayCenter(self::$logoPath, self::LOGO_SIZE, self::LOGO_SIZE, 0.5)
            ->toBinary(new EncodeOptions(format: 'png'));

        $rgb = $this->rgbAt($binary, 100, 100);
        self::assertSame(255, $rgb[0]);
        self::assertGreaterThanOrEqual(120, $rgb[1]);
        self::assertLessThanOrEqual(136, $rgb[1]);
    }

    public function testTextWatermarkAlphaUsesFullByteRange(): void
    {
        self::assertGreaterThanOrEqual(
            250,
            $this->maxChannel($this->textWatermarkedImage('#ffffff', 1.0), 'red'),
            'opacity=1.0 的文字应接近纯白',
        );

        $half = $this->maxChannel($this->textWatermarkedImage('#ffffff', 0.5), 'red');
        self::assertGreaterThanOrEqual(120, $half, 'opacity=0.5 的 alpha 应≈128');
        self::assertLessThanOrEqual(136, $half, 'opacity=0.5 的 alpha 应≈128');
    }

    public function testTextWatermarkAcceptsShortHexAndNamedColor(): void
    {
        // 旧实现会拼成 #fffXX，抛 InvalidArgumentException
        $short = $this->textWatermarkedImage('#fff', 1.0);
        self::assertGreaterThanOrEqual(250, $this->maxChannel($short, 'red'));

        // 旧实现会拼成 redXX，抛 ColorDecoderException
        $named = $this->textWatermarkedImage('red', 1.0);
        self::assertTrue(
            $this->hasPixel($named, static fn (array $c): bool => $c[0] >= 200 && $c[1] <= 60 && $c[2] <= 60),
            '命名色 red 应渲染为红色文字',
        );
    }

    public function testTextWatermarkRejectsInvalidColorWithModuleException(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->textWatermarkedImage('not-a-color', 1.0);
    }

    public function testQrcodeWithLogoRendersVisibleLogo(): void
    {
        $binary = (new QrcodeManager([], null, $this->manager))
            ->make('logo-test')
            ->errorCorrection('H')
            ->withLogo(self::$logoPath, 0.15)
            ->png()
            ->binary;

        self::assertTrue(
            $this->hasPixel($binary, static fn (array $c): bool => $c[0] >= 200 && $c[1] <= 80 && $c[2] <= 80),
            'QrcodeBuilder::withLogo() 必须在码图中心渲染可见的 logo',
        );
    }

    private function watermarkedImage(float $opacity): string
    {
        return $this->manager->create(200, 120, '#ffffff')
            ->watermarkImage(new ImageWatermark(self::$logoPath, 'top-left', self::OFFSET, self::OFFSET, $opacity))
            ->toBinary(new EncodeOptions(format: 'png'));
    }

    private function textWatermarkedImage(string $color, float $opacity): string
    {
        return $this->manager->create(320, 120, '#000000')
            ->watermarkText(new TextWatermark(
                content: 'ABC',
                font: $this->font,
                size: 60,
                color: $color,
                opacity: $opacity,
                position: 'top-left',
                offsetX: 10,
                offsetY: 10,
            ))
            ->toBinary(new EncodeOptions(format: 'png'));
    }

    /**
     * 红色像素包围盒（判定阈值：r≥200 且 g、b≤80）。
     *
     * @return array{int, int, int, int} [minX, minY, maxX, maxY]
     */
    private function redBox(string $binary): array
    {
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = -1;

        foreach ($this->pixels($binary) as $x => $column) {
            foreach ($column as $y => $color) {
                if ($color[0] < 200 || $color[1] > 80 || $color[2] > 80) {
                    continue;
                }
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        self::assertGreaterThanOrEqual(0, $maxX, '输出图中找不到 logo 像素');

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * @return array{int, int, int}
     */
    private function rgbAt(string $binary, int $x, int $y): array
    {
        $image = imagecreatefromstring($binary);
        self::assertNotFalse($image);
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        imagedestroy($image);

        return [$color['red'], $color['green'], $color['blue']];
    }

    /**
     * @param 'red'|'green'|'blue' $channel
     */
    private function maxChannel(string $binary, string $channel): int
    {
        $max = 0;
        foreach ($this->pixels($binary) as $column) {
            foreach ($column as $color) {
                $max = max($max, match ($channel) {
                    'red' => $color[0],
                    'green' => $color[1],
                    default => $color[2],
                });
            }
        }

        return $max;
    }

    /**
     * @param callable(array{int, int, int}): bool $predicate
     */
    private function hasPixel(string $binary, callable $predicate): bool
    {
        foreach ($this->pixels($binary) as $column) {
            foreach ($column as $color) {
                if ($predicate($color)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array<int, array{int, int, int}>> [x][y] => [r, g, b]
     */
    private function pixels(string $binary): array
    {
        $image = imagecreatefromstring($binary);
        self::assertNotFalse($image);

        $pixels = [];
        for ($x = 0; $x < imagesx($image); $x++) {
            for ($y = 0; $y < imagesy($image); $y++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $pixels[$x][$y] = [$color['red'], $color['green'], $color['blue']];
            }
        }
        imagedestroy($image);

        return $pixels;
    }
}
