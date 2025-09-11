<?php

declare(strict_types=1);

namespace Common\Library\OpenTelemetry\Contrib\Context\Swoole;

use Common\Library\OpenTelemetry\Context\ContextInterface;
use Common\Library\OpenTelemetry\Context\ContextStorageScopeInterface;
use Common\Library\OpenTelemetry\Context\ScopeInterface;

/**
 * @internal
 */
final class SwooleContextScope implements ScopeInterface, ContextStorageScopeInterface
{
    private ContextStorageScopeInterface $scope;
    private SwooleContextHandler $handler;

    public function __construct(ContextStorageScopeInterface $scope, SwooleContextHandler $handler)
    {
        $this->scope = $scope;
        $this->handler = $handler;
    }

    public function offsetExists($offset): bool
    {
        return $this->scope->offsetExists($offset);
    }

    /**
     * @phan-suppress PhanUndeclaredClassAttribute
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->scope->offsetGet($offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->scope->offsetSet($offset, $value);
    }

    public function offsetUnset($offset): void
    {
        $this->scope->offsetUnset($offset);
    }

    public function context(): ContextInterface
    {
        return $this->scope->context();
    }

    public function detach(): int
    {
        $this->handler->switchToActiveCoroutine();

        return $this->scope->detach();
    }
}
