<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace\SpanExporter;

use Common\Library\OpenTelemetry\SDK\Trace\Behavior\LoggerAwareTrait;
use Common\Library\OpenTelemetry\SDK\Trace\Behavior\SpanExporterDecoratorTrait;
use Common\Library\OpenTelemetry\SDK\Trace\Behavior\UsesSpanConverterTrait;
use Common\Library\OpenTelemetry\SDK\Trace\SpanConverterInterface;
use Common\Library\OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

class LoggerDecorator implements SpanExporterInterface, LoggerAwareInterface
{
    use SpanExporterDecoratorTrait;
    use UsesSpanConverterTrait;
    use LoggerAwareTrait;

    public function __construct(
        SpanExporterInterface $decorated,
        ?LoggerInterface $logger = null,
        ?SpanConverterInterface $converter = null,
    ) {
        $this->setDecorated($decorated);
        $this->setLogger($logger ?? new NullLogger());
        $this->setSpanConverter($converter ?? new FriendlySpanConverter());
    }

    #[\Override]
    protected function beforeExport(iterable $spans): iterable
    {
        return $spans;
    }

    #[\Override]
    protected function afterExport(iterable $spans, bool $exportSuccess): void
    {
        if ($exportSuccess) {
            $this->log(
                'Status Success',
                $this->getSpanConverter()->convert($spans),
                LogLevel::INFO,
            );
        } else {
            $this->log(
                'Status Failed Retryable',
                $this->getSpanConverter()->convert($spans),
                LogLevel::ERROR,
            );
        }
    }
}
