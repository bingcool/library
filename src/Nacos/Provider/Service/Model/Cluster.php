<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Service\Model;

use Common\Library\Nacos\Model\BaseModel;

class Cluster extends BaseModel
{
    protected string $name = '';

    protected ?HealthChecker $healthChecker = null;

    protected ?array $metadata = null;

    public function __construct(array $data = [])
    {
        if ($data) {
            foreach ($data as $k => $v) {
                if ('healthChecker' === $k) {
                    $v = new HealthChecker($v);
                }
                $this->$k = $v;
            }
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHealthChecker(): ?HealthChecker
    {
        return $this->healthChecker;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
}
