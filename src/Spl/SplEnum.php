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

namespace Common\Library\Spl;


class SplEnum
{
    private $val = null;
    private $name = null;

    final public function __construct($val)
    {
        $list = self::getConstants();
        //禁止重复值
        if (count($list) != count(array_unique($list))) {
            $class = static::class;
            throw new \Exception("class : {$class} define duplicate value");
        }
        $this->val = $val;
        $this->name = self::isValidValue($val);
        if($this->name === false){
            throw new \Exception("invalid value");
        }
    }

    final public function getName():string
    {
        return $this->name;
    }

    final public function getValue()
    {
        return $this->val;
    }

    final public static function isValidName(string $name):bool
    {
        $list = self::getConstants();
        if(isset($list[$name])){
            return true;
        }else{
            return false;
        }
    }

    final public static function isValidValue($val)
    {
        $list = self::getConstants();
        return array_search($val,$list);
    }

    final public static function getEnumList():array
    {
        return self::getConstants();
    }

    private static function getConstants():array
    {
        try{
            return (new \ReflectionClass(static::class))->getConstants();
        }catch (\Throwable $throwable){
            return [];
        }
    }

    function __toString()
    {
        // TODO: Implement __toString() method.
        return (string)$this->getName();
    }
}