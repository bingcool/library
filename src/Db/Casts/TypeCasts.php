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

namespace Common\Library\Db\Casts;

class TypeCasts
{
    const TYPE_STRING = 'string';

    const TYPE_INT = 'int';

    const TYPE_FLOAT = 'float';

    const TYPE_BOOL = 'bool';

    // 与json一致
    const TYPE_ARRAY = 'array';

    const TYPE_JSON = 'json';

    const TYPE_OBJECT = 'object';

    const TYPE_DATETIME = 'datetime';

    const TYPE_TIMESTAMP = 'timestamp';
}