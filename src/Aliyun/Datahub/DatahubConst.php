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

namespace Swoolefy\Library\Aliyun\Datahub;

/**
 * Aliyun DataHub 记录类型常量。
 *
 * @see https://help.aliyun.com/zh/datahub/
 */
class DatahubConst
{
    /** 结构化（Tuple）记录 */
    const RecordTypeTuple = 'TUPLE';

    /** 非结构化（Blob）记录 */
    const RecordTypeBlob = 'BLOB';
}
