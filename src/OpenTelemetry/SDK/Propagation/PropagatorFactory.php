<?php

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\SDK\Propagation;

use Swoolefy\Library\OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use Swoolefy\Library\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Configuration;
use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Variables;
use Swoolefy\Library\OpenTelemetry\SDK\Registry;

class PropagatorFactory
{
    use LogsMessagesTrait;

    public function create(): TextMapPropagatorInterface
    {
        $propagators = Configuration::getList(Variables::OTEL_PROPAGATORS);

        return match (count($propagators)) {
            0 => new NoopTextMapPropagator(),
            1 => $this->buildPropagator($propagators[0]),
            default => new MultiTextMapPropagator($this->buildPropagators($propagators)),
        };
    }

    /**
     * @return list<TextMapPropagatorInterface>
     */
    private function buildPropagators(array $names): array
    {
        $propagators = [];
        foreach ($names as $name) {
            $propagators[] = $this->buildPropagator($name);
        }

        return $propagators;
    }

    private function buildPropagator(string $name): TextMapPropagatorInterface
    {
        try {
            return Registry::textMapPropagator($name);
        } catch (\RuntimeException $e) {
            self::logWarning($e->getMessage());
        }

        return NoopTextMapPropagator::getInstance();
    }
}
