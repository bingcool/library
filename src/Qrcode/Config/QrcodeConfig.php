<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode\Config;

/** `Config/qrcode.php` 只读访问器 */
final class QrcodeConfig
{
    /**
     * @param array<string, mixed> $config 已剥离外层 qrcode 键
     */
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['qrcode']) && is_array($config['qrcode'])) {
            $config = $config['qrcode'];
        }

        return new self($config);
    }

    /**
     * default 段：size、margin、level、format、fill 等。
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $default = $this->config['default'] ?? [];

        return is_array($default) ? $default : [];
    }

    /**
     * logo 段：enabled、path、ratio（ratio 亦作为 withLogo 上限参考）。
     *
     * @return array<string, mixed>
     */
    public function logo(): array
    {
        $logo = $this->config['logo'] ?? [];

        return is_array($logo) ? $logo : [];
    }

    /** Bacon 编码仍失败时是否统一抛「内容过长」而非原始异常信息 */
    public function strictLength(): bool
    {
        return (bool) ($this->config['strict_length'] ?? true);
    }

    /** withLogo 允许的最大 ratio，默认 0.18 */
    public function maxLogoRatio(): float
    {
        return (float) ($this->logo()['ratio'] ?? 0.18);
    }
}
