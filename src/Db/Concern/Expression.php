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
    /**
     * @var array
     */
    protected $expressionFields = [];

    /**
     * 表达式,重复设置相同field，将以最后表达式为准
     */
    public function exp(string $field, string $expression): void
    {
        if(!$this->isNew()) {
            $this->expressionFields['*@'.$field] = $expression;
        }
    }

    /**
     * 字段自增,可多次自增
     * @param string $field
     * @param float  $num
     */
    public function inc(string $field, float $num): void
    {
        if(is_numeric($this->$field) && !$this->isNew() && $num != 0) {
            if(!isset($this->expressionFields['+@'.$field])) {
                $this->expressionFields['+@'.$field] = 0;
            }
            $this->expressionFields['+@'.$field] += abs($num);
        }
    }

    /**
     * 字段自减,可多次自减
     * @param string $field
     * @param float  $num
     */
    public function sub(string $field, float $num): void
    {
        if(is_numeric($this->$field) && !$this->isNew() && $num != 0) {
            if(!isset($this->expressionFields['-@'.$field])) {
                $this->expressionFields['-@'.$field] = 0;
            }
            $this->expressionFields['-@'.$field] += abs($num);
        }
    }

    /**
     * @return array
     */
    public function getExpFields()
    {
        $fields = [];
        foreach ($this->expressionFields as $field) {
            $fieldArr = explode('@', $field);
            $fields[] = $fieldArr[1];
        }
        return array_unique($fields);
    }
}