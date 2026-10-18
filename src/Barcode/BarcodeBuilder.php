<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode;

use Swoolefy\Library\Barcode\Config\BarcodeConfig;
use Swoolefy\Library\Barcode\Exception\UnsupportedBarcodeTypeException;
use Swoolefy\Library\Barcode\Renderer\BarcodeRenderer;
use Swoolefy\Library\Barcode\Support\TypeMapper;
use Swoolefy\Library\Barcode\ValueObject\BarcodeOptions;
use Swoolefy\Library\Barcode\ValueObject\BarcodeResult;

/**
 * 链式配置条码类型与尺寸，以 {@see svg()} / {@see png()} 终结输出。
 *
 * 编码前由 {@see TypeMapper} 做制式级校验，再委托 picqer Renderers 输出。
 */
final class BarcodeBuilder
{
    /** 当前 Builder 的渲染选项（可变，仅限本 Builder 生命周期） */
    private BarcodeOptions $options;

    /**
     * @param string $content 初始条码载荷
     * @param BarcodeConfig $config 提供 default.type / height / width / show_text 等
     */
    public function __construct(string $content, private BarcodeConfig $config)
    {
        $defaults = $this->config->defaults();
        $this->options = new BarcodeOptions(
            content: $content,
            type: BarcodeType::tryFrom(strtoupper((string) ($defaults['type'] ?? 'CODE_128'))) ?? BarcodeType::CODE_128,
            height: (int) ($defaults['height'] ?? 150),
            width: (int) ($defaults['width'] ?? 0),
            showText: (bool) ($defaults['show_text'] ?? true),
        );
    }

    /** 覆盖待编码载荷 */
    public function content(string $code): self
    {
        $this->options->content = $code;

        return $this;
    }

    /** 切换 picqer 制式（见 {@see BarcodeType}） */
    public function type(BarcodeType $type): self
    {
        $this->options->type = $type;

        return $this;
    }

    /** 条图高度（像素），最小为 1 */
    public function height(int $px): self
    {
        $this->options->height = max(1, $px);

        return $this;
    }

    /**
     * 输出总宽度（像素）。0 表示按条码 intrinsic 宽度自动放大。
     */
    public function width(int $px): self
    {
        $this->options->width = max(0, $px);

        return $this;
    }

    /** 是否显示人类可读文字（PNG/SVG 实际效果依赖 picqer 渲染器） */
    public function showText(bool $show = true): self
    {
        $this->options->showText = $show;

        return $this;
    }

    /**
     * 输出 SVG 字符串，无需 gd/imagick。
     *
     * @throws \Swoolefy\Library\Barcode\Exception\InvalidBarcodeContentException
     */
    public function svg(): BarcodeResult
    {
        $barcode = TypeMapper::encode($this->options->type, $this->options->content);
        $binary = BarcodeRenderer::svg($barcode, $this->options);

        return new BarcodeResult($binary, 'image/svg+xml', 'svg', $this->options->content);
    }

    /**
     * 输出 PNG 二进制，需要 ext-gd 或 ext-imagick 至少其一。
     *
     * @throws \Swoolefy\Library\Barcode\Exception\InvalidBarcodeContentException
     * @throws \Swoolefy\Library\Barcode\Exception\MissingExtensionException
     */
    public function png(): BarcodeResult
    {
        $barcode = TypeMapper::encode($this->options->type, $this->options->content);
        $binary = BarcodeRenderer::png($barcode, $this->options);

        return new BarcodeResult($binary, 'image/png', 'png', $this->options->content);
    }

    /**
     * 按格式渲染并返回 data URI。
     *
     * @param string|null $format svg|png；null 时使用配置 default.format
     *
     * @throws UnsupportedBarcodeTypeException 未知 format
     * @throws \Swoolefy\Library\Barcode\Exception\InvalidBarcodeContentException
     * @throws \Swoolefy\Library\Barcode\Exception\MissingExtensionException png 且缺扩展
     */
    public function base64(?string $format = null): string
    {
        $format = strtolower($format ?? (string) ($this->config->defaults()['format'] ?? 'svg'));
        $result = match ($format) {
            'png' => $this->png(),
            'svg' => $this->svg(),
            default => throw new UnsupportedBarcodeTypeException(sprintf('Unsupported barcode format [%s]', $format)),
        };

        return 'data:' . $result->mime . ';base64,' . base64_encode($result->binary);
    }
}
