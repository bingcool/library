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

namespace Common\Library\Db;

/**
 * SQL Raw
 */
class Raw
{
    /**
     * 查询表达式
     *
     * @var string
     */
    protected $value;

    /**
     * 参数绑定
     *
     * @var array
     */
    protected $bind = [];

    /**
     * 创建一个查询表达式
     *
     * @param  string  $value
     * @param  array   $bind
     * @return void
     */
    public function __construct(string $value, array $bind = [])
    {
        $this->value = $value;
        $this->bind  = $bind;
    }

    /**
     * 获取表达式
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * 获取参数绑定
     *
     * @return string
     */
    public function getBind(): array
    {
        return $this->bind;
    }

}
