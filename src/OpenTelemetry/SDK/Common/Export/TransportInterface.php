<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Common\Export;

use Common\Library\OpenTelemetry\SDK\Common\Future\CancellationInterface;
use Common\Library\OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * @psalm-template-covariant CONTENT_TYPE of string
 */
interface TransportInterface
{
    public function contentType(): string;

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface;

    public function shutdown(?CancellationInterface $cancellation = null): bool;

    public function forceFlush(?CancellationInterface $cancellation = null): bool;
}
