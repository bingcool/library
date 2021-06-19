<?php

namespace Common\Library\Tests\Db;

trait PropertyFormat
{
    protected function setOrderProductIdsAttr($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    protected function getOrderProductIdsAttr($value)
    {
        return json_decode($value, true);
    }

    protected function setJsonDataAttr($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    protected function getJsonDataAttr($value)
    {
        return json_decode($value, true);
    }
}