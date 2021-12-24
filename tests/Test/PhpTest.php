<?php

namespace Common\Library\Tests\Test;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;

class PhpTest extends TestCase
{
    public $arr = [1, 2, 45, 67, 21, 89, 44, 66, 33];

    public function testQuickSort()
    {
        $arr = [1, 2, 45, 67, 21, 89, 44, 66, 33];
        $result = $this->quickSort($arr);
        var_dump($result);
    }

    // 快速排序法
    protected function quickSort($arr)
    {
        $length = count($arr);

        if ($length <= 1) {
            return $arr;
        }

        $middle = $arr[0];

        $right = $left = [];

        for ($i = 1; $i < $length; $i++) {
            if ($arr[$i] > $middle) {
                $right[] = $arr[$i];
            } else {
                $left[] = $arr[$i];
            }
        }

        $right = $this->quickSort($right);
        $left = $this->quickSort($left);

        return array_merge($left, [$middle], $right);
    }

    public function testMaoSort()
    {
        $arr = [1, 2, 45, 67, 21, 89, 44, 66, 33];
        $result = $this->maoSort($arr);
        var_dump($result);
    }

    public function testSelectSort()
    {
        $arr = [1, 2, 45, 67, 21, 89, 44, 66, 33];
        $result = $this->selectSort($arr);
        var_dump($result);
    }

    protected function maoSort($arr)
    {
        $length = count($arr);
        if ($length <= 1) {
            return $arr;
        }

        for ($i = 1; $i < $length; $i++) {
            for ($j = 0; $j < $length - $i; $j++) {
                if ($arr[$j] > $arr[$j + 1]) {
                    $tmp = $arr[$j];
                    $arr[$j] = $arr[$j + 1];
                    $arr[$j + 1] = $tmp;
                }
            }
        }

        return $arr;
    }

    /**
     * 选择排序法
     * @param $arr
     * @return mixed
     */
    protected function selectSort($arr)
    {
        $length = count($arr);

        for ($i = 0; $i < $length; $i++) {
            for ($j = $i + 1; $j < $length; $j++) {
                if ($arr[$i] < $arr[$j]) {
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
        $binTree = new \stdClass();
        $binTree->data = null;
        $binTree->left = null;
        $binTree->right = null;

    }

    /**
     * @param array $arr 二分查找的数据必须是有序的线性链表数据
     * @param $number
     * @return int
     */
    public function testBinarySearch($arr, $number)
    {
        // 非数组或者数组为空，直接返回-1
        if (!is_array($arr) || empty($arr)) {
            return -1;
        }
        // 初始变量值
        $len = count($arr);
        $lower = 0;
        $high = $len - 1;
        // 最低点比最高点大就退出
        while ($lower <= $high) {
            // 以中间点作为参照点比较
            $middle = intval(($lower + $high) / 2);
            if ($arr[$middle] > $number) {
                // 查找数比参照点小，舍去右边
                $high = $middle - 1;
            } else if ($arr[$middle] < $number) {
                // 查找数比参照点大，舍去左边
                $lower = $middle + 1;
            } else {
                // 查找数与参照点相等，则找到返回
                return $middle;
            }
        }
        // 未找到，返回-1
        return -1;
    }

}

