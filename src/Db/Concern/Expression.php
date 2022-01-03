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

namespace Common\Library\Db\Concern;

/**
 * Trait Expression
 * @package Common\Library\Db\Concern
 */
trait Expression
{
    protected $expressionFields = [];

    /**
     * 表达式
     */
    public function exp(string $field, string $expression)
    {
        $this->expressionFields[] = $field;
        return $expression;
    }

    /**
     * 字段自增
     */
    public function inc(string $field, int $num)
    {
        if(is_numeric($this->$field) && !$this->isNew()) {
            $this->expressionFields[] = $field;
            return $field."+".$num;
        }
    }

    /**
     * 字段自减
     */
    public function sub(string $field, int $num)
    {
        if(is_numeric($this->$field) && !$this->isNew()) {
            $this->expressionFields[] = $field;
            return $field.'-'.$num;
        }
    }
}