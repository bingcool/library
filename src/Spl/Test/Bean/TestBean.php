<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Common\Library\Spl\Test\Bean;

use Common\Library\Spl\SplBean;
use Common\Library\Spl\Test\Bean\Shops;

class TestBean extends SplBean
{
    public $a = 2;
    protected $b;
    private $c;
    protected $d_d;
//    protected $shops; // 测试setClassMapping

    protected function setKeyMapping(): array
    {
        return [
            'd-d'=>"d_d"
        ];
    }

//    protected function setClassMapping(): array
//    {
//        return [
//            'shops' => Shops::class
//        ];
//    }

}