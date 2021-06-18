<?php

namespace Common\Library\Tests\Test;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;

class PhpTest extends TestCase
{
    public $arr = [1,2,45,67,21,89,44,66,33];

    public function testQuickSort()
    {
        $arr = [1,2,45,67,21,89,44,66,33];
        $result = $this->quickSort($arr);
        var_dump($result);
    }
    // 快速排序法
    protected function quickSort($arr)
    {
        $length = count($arr);

        if($length <=1)
        {
            return $arr;
        }

        $middle = $arr[0];

        $right = $left = [];

        for($i=1;$i<$length;$i++)
        {
            if($arr[$i] > $middle)
            {
                $right[] = $arr[$i];
            }else {
                $left[] = $arr[$i];
            }
        }

        $right = $this->quickSort($right);
        $left = $this->quickSort($left);

        return array_merge($left, [$middle], $right);
    }

    public function testMaoSort()
    {
        $arr = [1,2,45,67,21,89,44,66,33];
        $result = $this->maoSort($arr);
        var_dump($result);
    }

    protected function maoSort($arr)
    {
        $length = count($arr);

        for($i=0;$i<$length;$i++)
        {
            for($j=$i+1;$j<$length;$j++)
            {
                if($arr[$i] < $arr[$j])
                {
                    $temp = $arr[$i];
                    $arr[$i] = $arr[$j];
                    $arr[$j] = $temp;
                }
            }
        }

        return $arr;
    }

    public function testQueryString()
    {

    }

}

