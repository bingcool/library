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

class TestAllProperty extends SplBean{

    // static
    public static $staticA;
    protected static $staticB;
    private static $staticC;

    // static赋初值
    public static $staticInitA='';
    protected static $staticInitB='';
    private static $staticInitC='';

    // 普通
    public $a;
    protected $b;
    private $c;

    // 普通赋初值
    public $aInit='';
    protected $bInit='';
    private $cInit='';

}

class TestAllProperty74 extends SplBean{

    // static
    public static $staticA;
    protected static $staticB;
    private static $staticC;

    // static赋初值
    public static $staticInitA='';
    protected static $staticInitB='';
    private static $staticInitC='';

    // 普通
    public $a;
    protected $b;
    private $c;

    // 普通赋初值
    public $aInit='';
    protected $bInit='';
    private $cInit='';

    // 约束类型
    public string $typeA;
    protected int $typeB;
    private bool $typeC;

    // 约束类型赋初值
    public string $typeInitA='';
    protected int $typeInitB=1;
    private bool $typeInitC=true;

}
