<?php

namespace Common\Library\Tests\ArrayUtil;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;
use Common\Library\ArrayHelper\ArrayUtil;

class ArrayTest extends TestCase
{
    public function testDiffArr()
    {
        $arr1 = [
            'name' => 'bingcool',
            'sex' => 1,
            'client_id' => 2345,
            'data' => ['name' => 'vvv'],
        ];

        $arr2 = [
            'name' => 'bingcool2',
            'sex' => 2,
            'client_id' => 2345,
            'data' => ['name' => 'vvv11'],
        ];


        $diff = \Common\Library\ArrayHelper\ArrayUtil::dirtyArray($arr1, $arr2);

        var_dump($diff);
    }

    public function testArraySort()
    {
        $arr = [
            [
                'name' => 'bingcool',
                'age' => 18,
                'client_id' => 2345,
                'data' => ['name' => 'vvv'],
            ],
            [
                'name' => 'bingcool2',
                'age' => '20',
                'client_id' => 2345,
                'data' => ['name' => 'vvv11'],
            ],
            [
                'name' => 'bingcool2',
                'age' => 6,
                'client_id' => 2345,
                'data' => ['name' => 'vvv11'],
            ]
        ];

        try {
            $newArr = \Common\Library\ArrayHelper\ArrayUtil::sortDataArr($arr, 'age', 'asc', false);
            var_dump($newArr);

        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }

    }

    public function testMerge()
    {
        $arr1 = [
            'name' => 'bingcool',
            'sex' => 1,
            'client_id' => 2345,
            'data' => [
                'name' => 'vvv9999',
                'city' => 'Gunagzhou'
            ],
        ];

        $arr2 = [
            'name' => 'bingcool2',
            'sex' => 2,
            'client_id' => 2345,
            'data' => [
                'name' => 'vvv11',
                'country' => 'CN'
            ],
        ];

        $arr = ArrayUtil::merge($arr1, $arr2, true);

        var_dump($arr);

    }
}