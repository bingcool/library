<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode;

use Psr\Log\LoggerInterface;
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
     * @param LoggerInterface|null $logger 用于记录配置异常（如无法识别的 default.type）
     */
    public function __construct(
        string $content,
        private BarcodeConfig $config,
        private ?LoggerInterface $logger = null,
    ) {
        $defaults = $this->config->defaults();
        $this->options = new BarcodeOptions(
            content: $content,
            type: $this->resolveType($defaults['type'] ?? null),
            height: max(1, (int) ($defaults['height'] ?? 150)),
            width: max(0, (int) ($defaults['width'] ?? 0)),
            showText: (bool) ($defaults['show_text'] ?? true),
        );
    }

    /**
     * 解析配置默认制式；无法识别时回退 CODE_128 并记录告警，避免静默生成错误制式。
     *
     * @param mixed $raw 配置 default.type 原始值
     */
    private function resolveType(mixed $raw): BarcodeType
    {
        $name = strtoupper(trim((string) ($raw ?? 'CODE_128')));
        $type = BarcodeType::tryFrom($name);
        if ($type !== null) {
            return $type;
        }

        $this->logger?->warning(sprintf(
            'Unknown barcode type [%s] in config default.type, fallback to CODE_128',
            $name
        ));

        return BarcodeType::CODE_128;
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

    /**
     * 是否显示人类可读文字。
     *
     * SVG 输出在底部叠加 {@see \Swoolefy\Library\Barcode\Renderer\BarcodeRenderer} 自绘的 &lt;text&gt;；
     * PNG 受 picqer v3 渲染器限制仍不绘制文字，此标志对 PNG 无效。
     */
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
