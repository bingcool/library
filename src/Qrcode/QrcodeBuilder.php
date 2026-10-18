<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode;

use BaconQrCode\Exception\ExceptionInterface as BaconException;
use BaconQrCode\Exception\WriterException;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Qrcode\Config\QrcodeConfig;
use Swoolefy\Library\Qrcode\Exception\InvalidQrcodeContentException;
use Swoolefy\Library\Qrcode\Exception\QrcodeException;
use Swoolefy\Library\Qrcode\Exception\UnsupportedQrcodeFormatException;
use Swoolefy\Library\Qrcode\Renderer\QrcodeWriterFactory;
use Swoolefy\Library\Qrcode\Support\ErrorCorrectionMapper;
use Swoolefy\Library\Qrcode\Support\LogoApplier;
use Swoolefy\Library\Qrcode\ValueObject\QrcodeOptions;
use Swoolefy\Library\Qrcode\ValueObject\QrcodeResult;
use Throwable;

/**
 * 链式配置二维码参数，以 {@see png()}/{@see svg()}/{@see eps()} 终结输出。
 *
 * {@see withLogo()} 为修饰方法，须在终结输出前调用；仅 PNG 路径会叠加 Logo。
 */
final class QrcodeBuilder
{
    /** 当前 Builder 的渲染选项（可变，仅限本 Builder 生命周期） */
    private QrcodeOptions $options;

    /**
     * @param string $content 初始待编码 UTF-8 文本
     * @param QrcodeConfig $config 全局默认 size/margin/level 等
     * @param ImageManager|null $image Logo 叠加依赖；null 时 withLogo 后在 png() 抛 {@see QrcodeException}
     */
    public function __construct(
        string $content,
        private QrcodeConfig $config,
        private ?ImageManager $image = null,
    ) {
        $this->options = (new QrcodeOptions(content: $content))->withDefaults($this->config->defaults());
    }

    /** 覆盖待编码内容 */
    public function content(string $text): self
    {
        $this->options = new QrcodeOptions(
            content: $text,
            size: $this->options->size,
            margin: $this->options->margin,
            level: $this->options->level,
            fill: $this->options->fill,
            logoPath: $this->options->logoPath,
            logoRatio: $this->options->logoRatio,
        );

        return $this;
    }

    /** 输出图像边长（像素），映射 Bacon RendererStyle.size */
    public function size(int $px): self
    {
        $this->options->size = max(1, $px);

        return $this;
    }

    /** 静区模块数（margin） */
    public function margin(int $modules): self
    {
        $this->options->margin = max(0, $modules);

        return $this;
    }

    /**
     * 纠错等级 L|M|Q|H。
     *
     * @throws InvalidQrcodeContentException 非法等级字符
     */
    public function errorCorrection(string $level): self
    {
        ErrorCorrectionMapper::map($level);
        $this->options->level = strtoupper(trim($level));

        return $this;
    }

    /** 前景色 hex（二维码模块颜色） */
    public function foreground(string $hex): self
    {
        $this->options->fill[1] = $hex;

        return $this;
    }

    /** 背景色 hex */
    public function background(string $hex): self
    {
        $this->options->fill[0] = $hex;

        return $this;
    }

    /**
     * 声明 PNG 输出后要居中叠加的 Logo（不立即渲染，在 png() 时生效）。
     *
     * @param float $ratio Logo 边长占码图宽度比例，须 ≤ 配置 maxLogoRatio，且纠错须 Q 或 H
     *
     * @throws InvalidQrcodeContentException 比例或纠错不满足
     */
    public function withLogo(string $logoPath, float $ratio = 0.18): self
    {
        if (!in_array($this->options->level, ['Q', 'H'], true)) {
            throw new InvalidQrcodeContentException('Logo overlay requires error correction level Q or H');
        }

        $max = $this->config->maxLogoRatio();
        if ($ratio <= 0 || $ratio > $max) {
            throw new InvalidQrcodeContentException(sprintf('Logo ratio must be > 0 and <= %s', $max));
        }

        $this->options->logoPath = $logoPath;
        $this->options->logoRatio = $ratio;

        return $this;
    }

    /** 输出 PNG 二进制，需要 ext-gd */
    public function png(): QrcodeResult
    {
        return $this->render('png');
    }

    /** 输出 SVG 字符串，需要 ext-xmlwriter */
    public function svg(): QrcodeResult
    {
        return $this->render('svg');
    }

    /** 输出 EPS，需要 ext-xmlwriter */
    public function eps(): QrcodeResult
    {
        return $this->render('eps');
    }

    /**
     * 按格式渲染并返回 data URI。
     *
     * @param string|null $format png|svg|eps；null 用配置 default.format
     */
    public function base64(?string $format = null): string
    {
        $format = $format ?? (string) ($this->config->defaults()['format'] ?? 'png');
        $result = match (strtolower($format)) {
            'svg' => $this->svg(),
            'eps' => $this->eps(),
            'png' => $this->png(),
            default => throw new UnsupportedQrcodeFormatException(sprintf('Unsupported QR format [%s]', $format)),
        };

        return 'data:' . $result->mime . ';base64,' . base64_encode($result->binary);
    }

    /**
     * @throws InvalidQrcodeContentException 内容空、Bacon 编码失败、strict_length 下过长
     * @throws UnsupportedQrcodeFormatException
     * @throws QrcodeException withLogo 但未注入 ImageManager
     */
    private function render(string $format): QrcodeResult
    {
        $content = trim($this->options->content);
        if ($content === '') {
            throw new InvalidQrcodeContentException('QR content is required');
        }

        $writer = match ($format) {
            'png' => QrcodeWriterFactory::png($this->options),
            'svg' => QrcodeWriterFactory::svg($this->options),
            'eps' => QrcodeWriterFactory::eps($this->options),
            default => throw new UnsupportedQrcodeFormatException(sprintf('Unsupported QR format [%s]', $format)),
        };

        try {
            $binary = $writer->writeString($content, encoding: 'UTF-8', ecLevel: ErrorCorrectionMapper::map($this->options->level));
        } catch (WriterException|BaconException $e) {
            if ($this->config->strictLength()) {
                throw new InvalidQrcodeContentException('QR content is too long for the selected error correction level', 0, $e);
            }
            throw new InvalidQrcodeContentException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new InvalidQrcodeContentException($e->getMessage(), 0, $e);
        }

        if ($format === 'png' && $this->options->logoPath !== null) {
            if ($this->image === null) {
                throw new QrcodeException('Image component is required for withLogo(); register Application image service first');
            }
            $binary = (new LogoApplier($this->image))->apply($binary, $this->options->logoPath, $this->options->logoRatio);
        }

        $mime = match ($format) {
            'svg' => 'image/svg+xml',
            'eps' => 'application/postscript',
            default => 'image/png',
        };

        return new QrcodeResult($binary, $mime, $format, $content);
    }
}
