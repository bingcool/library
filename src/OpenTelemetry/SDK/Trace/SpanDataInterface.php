<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\SDK\Trace;

use Common\Library\OpenTelemetry\API\Trace as API;
use Common\Library\OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use Common\Library\OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use Common\Library\OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Represents an immutable snapshot of a {@see API\SpanInterface}.
 *
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.6.1/specification/trace/sdk.md#additional-span-interfaces
 */
interface SpanDataInterface
{
    public function getName(): string;
    public function getKind(): int;
    public function getContext(): API\SpanContextInterface;
    public function getParentContext(): API\SpanContextInterface;
    public function getTraceId(): string;
    public function getSpanId(): string;
    public function getParentSpanId(): string;
    public function getStatus(): StatusDataInterface;
    public function getStartEpochNanos(): int;
    public function getAttributes(): AttributesInterface;

    /** @return list<EventInterface> */
    public function getEvents(): array;

    /** @return list<LinkInterface> */
    public function getLinks(): array;

    public function getEndEpochNanos(): int;
    public function hasEnded(): bool;
    public function getInstrumentationScope(): InstrumentationScopeInterface;
    public function getResource(): ResourceInfo;

    /** @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.6.1/specification/trace/sdk_exporters/non-otlp.md#dropped-events-count */
    public function getTotalDroppedEvents(): int;

    /** @see https://github.com/open-telemetry/opentelemetry-specification/blob/v1.6.1/specification/trace/sdk_exporters/non-otlp.md#dropped-links-count */
    public function getTotalDroppedLinks(): int;
}
