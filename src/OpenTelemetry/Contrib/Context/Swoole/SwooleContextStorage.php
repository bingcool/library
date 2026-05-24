<?php

/** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */

declare(strict_types=1);

namespace Swoolefy\Library\OpenTelemetry\Contrib\Context\Swoole;

use Swoolefy\Library\OpenTelemetry\Context\ContextInterface;
use Swoolefy\Library\OpenTelemetry\Context\ContextStorageInterface;
use Swoolefy\Library\OpenTelemetry\Context\ContextStorageScopeInterface;
use Swoolefy\Library\OpenTelemetry\Context\ExecutionContextAwareInterface;

/** @psalm-api */
final class SwooleContextStorage implements ContextStorageInterface, ExecutionContextAwareInterface
{
    /** @var ContextStorageInterface&ExecutionContextAwareInterface */
    private ContextStorageInterface $storage;
    private SwooleContextHandler $handler;

    /**
     * @param ContextStorageInterface&ExecutionContextAwareInterface $storage
     */
    public function __construct(ContextStorageInterface $storage)
    {
        $this->storage = $storage;
        $this->handler = new SwooleContextHandler($storage);
    }

    public function fork(int|string $id): void
    {
        $this->handler->switchToActiveCoroutine();

        $this->storage->fork($id);
    }

    public function switch(int|string $id): void
    {
        $this->handler->switchToActiveCoroutine();

        $this->storage->switch($id);
    }

    public function destroy(int|string $id): void
    {
        $this->handler->switchToActiveCoroutine();

        $this->storage->destroy($id);
    }

    public function scope(): ?ContextStorageScopeInterface
    {
        $this->handler->switchToActiveCoroutine();

        if (($scope = $this->storage->scope()) === null) {
            return null;
        }

        return new SwooleContextScope($scope, $this->handler);
    }

    public function current(): ContextInterface
    {
        $this->handler->switchToActiveCoroutine();

        return $this->storage->current();
    }

    public function attach(ContextInterface $context): ContextStorageScopeInterface
    {
        $this->handler->switchToActiveCoroutine();
        $this->handler->splitOffChildCoroutines();

        $scope = $this->storage->attach($context);

        return new SwooleContextScope($scope, $this->handler);
    }
}
