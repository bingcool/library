<?php

namespace Swoolefy\Library\Tests\Db\Stub;

use Swoolefy\Library\Db\Interceptor\TenantLineHandlerInterface;

class FixedTenantHandler implements TenantLineHandlerInterface
{
    public function __construct(private readonly string $tenantId)
    {
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getTenantIdColumn(): string
    {
        return 'tenant_id';
    }

    public function ignoreTable(string $tableName): bool
    {
        return false;
    }
}
