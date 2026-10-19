<?php

declare(strict_types=1);

namespace Swoolefy\Library\Barcode;

use Psr\Log\LoggerInterface;
use Swoolefy\Library\Barcode\Config\BarcodeConfig;

/**
 * 条形码生成 DI 入口。无请求态，可跨协程共享同一 Manager 实例。
 *
 * 每次 {@see make()} 返回新的 {@see BarcodeBuilder}，链式配置后以 {@see BarcodeBuilder::svg()} /
 * {@see BarcodeBuilder::png()} 输出。
 */
final class BarcodeManager
{
    /**
     * @param array<string, mixed>|BarcodeConfig $config barcode 配置或已解析的 BarcodeConfig
     * @param LoggerInterface|null $logger PSR-3；透传给 Builder，用于记录无法识别的 default.type 等配置告警
     */
    public function __construct(
        private array|BarcodeConfig $config,
        private ?LoggerInterface $logger = null,
    ) {
        if (is_array($this->config)) {
            $this->config = BarcodeConfig::fromArray($this->config);
        }
    }

    /**
     * 创建 Builder 并预填待编码载荷。
     *
     * 类型、高度、宽度等初值来自配置的 default 段。
     */
    public function make(string $content): BarcodeBuilder
    {
        return new BarcodeBuilder($content, $this->config, $this->logger);
    }
}
