<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\ValueObject;

/**
 * 单次二维码渲染参数（Builder 内部可变状态）。
 */
final class QrcodeOptions
{
    /**
     * @param array{0: string, 1: string} $fill [backgroundHex, foregroundHex]
     * @param string|null $logoPath 非 null 表示 png 输出后叠加 Logo
     */
    public function __construct(
        /** UTF-8 待编码正文 */
        public string $content = '',
        /** 输出边长（像素） */
        public int $size = 300,
        /** 静区模块数 */
        public int $margin = 1,
        /** L|M|Q|H */
        public string $level = 'M',
        public array $fill = ['#ffffff', '#000000'],
        public ?string $logoPath = null,
        /** Logo 占码宽比例 */
        public float $logoRatio = 0.18,
    ) {
    }

    /**
     * 用配置 default 段填充仍为默认值的字段。
     *
     * @param array<string, mixed> $defaults
     */
    public function withDefaults(array $defaults): self
    {
        $fill = $defaults['fill'] ?? $this->fill;
        if (!is_array($fill)) {
            $fill = $this->fill;
        }

        return new self(
            $this->content,
            $this->size ?: (int) ($defaults['size'] ?? 300),
            $this->margin ?: (int) ($defaults['margin'] ?? 1),
            $this->level !== '' ? $this->level : (string) ($defaults['level'] ?? 'M'),
            [
                (string) ($fill[0] ?? '#ffffff'),
                (string) ($fill[1] ?? '#000000'),
            ],
            $this->logoPath,
            $this->logoRatio,
        );
    }
}
