<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Common\Library\ArrayHelper;

/**
 * ArrayHelper provides additional array functionality that you can use in your
 * application.
 *
 * For more details and usage information on ArrayHelper, see the [guide article on array helpers](guide:helper-array).
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */

class ArrayUtil extends BaseArrayHelper
{
    /**
     * 比较关联数组数据值不同,发生变化的的$array1的字段数组
     * @param array $array1
     * @param array $array2
     */
    public static function dirtyArray(array $array1, array $array2)
    {
        $diff = array_udiff_assoc($array1, $array2, function ($a, $b) {
            if ((empty($a) || empty($b)) && $a !== $b) {
                return 1;
            }

            return is_object($a) || $a != $b ? 1 : 0;
        });

        return $diff;
    }

    /**
     * 二维数组按照某个字段排序,并取出某个范围的数据，默认返回全部
     * @param array $data
     * @param string $sort_field
     * @param string $sort_type
     * @param bool $preserve_key //是否保留数组原key
     * @param int $start
     * @param int $length
     * @return array
     */
    public static function sortDataArr(
        array $data,
        string $sort_field,
        string $sort_order,
        bool $preserve_key = true,
        int $start = 0,
        int $length = 0
    )
    {
        uasort($data, function ($a, $b) use ($sort_field, $sort_order) {
            $aValue = $a[$sort_field] ?? 0;
            $bValue = $b[$sort_field] ?? 0;
            if(!is_numeric($aValue) || !is_numeric($bValue))
            {
                throw new \Exception("field={$sort_field} of value must be number");
            }
            return strtoupper($sort_order) == 'ASC' ? $aValue <=> $bValue : $bValue <=> $aValue;
        });
        if ($length > 0) {
            $data = array_slice($data, $start, $length);
        }

        if($preserve_key === false)
        {
           return  array_values($data);
        }
        return $data;
    }
}
