<?php

declare(strict_types=1);

namespace Common\Library\Nacos\Provider\Traits;

trait TInitProperties
{
    public function __construct(array $data = [])
    {
        if ($data) {
            foreach ($data as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}
