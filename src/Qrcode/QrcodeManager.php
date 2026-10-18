<?php

declare(strict_types=1);

namespace Swoolefy\Library\Qrcode;

use Psr\Log\LoggerInterface;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Qrcode\Config\QrcodeConfig;
use Swoolefy\Library\Qrcode\Support\WifiStringBuilder;

/**
 * 二维码生成 DI 入口。无请求态，可跨协程共享同一 Manager 实例。
 *
 * 每次 {@see make()} 返回新的 {@see QrcodeBuilder}。Logo 叠加需构造时注入 {@see ImageManager}。
 */
final class QrcodeManager
{
    /**
     * @param array<string, mixed>|QrcodeConfig $config qrcode 配置
     * @param LoggerInterface|null $logger 预留 PSR-3
     * @param ImageManager|null $image 非 null 时 Builder 才支持 {@see QrcodeBuilder::withLogo()}
     */
    public function __construct(
        private array|QrcodeConfig $config,
        private ?LoggerInterface $logger = null,
        private ?ImageManager $image = null,
    ) {
        if (is_array($this->config)) {
            $this->config = QrcodeConfig::fromArray($this->config);
        }
    }

    /** 按配置默认创建 Builder，预填待编码内容 */
    public function make(string $content): QrcodeBuilder
    {
        return new QrcodeBuilder($content, $this->config, $this->image);
    }

    /** 便捷：内容为 URL 字符串（不做 URL 合法性校验，由业务保证） */
    public function url(string $url): QrcodeBuilder
    {
        return $this->make($url);
    }

    /**
     * 便捷：生成 WiFi 连接串再编码。
     *
     * @param string $auth WPA|WEP|nopass 等，见 {@see WifiStringBuilder}
     */
    public function wifi(string $ssid, string $password, string $auth = 'WPA'): QrcodeBuilder
    {
        return $this->make(WifiStringBuilder::build($ssid, $password, $auth));
    }
}
