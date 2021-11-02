<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\Db\Concern;

/**
 * Trait Util
 * @package Common\Library\Db\Concern
 */

trait Util {
    /**
     * 下划线转驼峰(首字母大写
     * @param  string $value
     * @return string
     */
    public static function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * 比较关联数组数据值不同,发生变化的的$array1的字段数组
     * @param array $array1
     * @param array $array2
     * @return array
     */
    public static function dirtyArray(array $array1, array $array2): array
    {
        $diff = array_udiff_assoc($array1, $array2, function ($a, $b) {
            if ((empty($a) || empty($b)) && $a !== $b) {
                return 1;
            }

            return is_object($a) || $a != $b ? 1 : 0;
        });

        return $diff ?? [];
    }

}