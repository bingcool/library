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
 * Trait Attribute
 * @package Common\Library\Db\Concern
 */
trait Attribute
{
    /**
     * primary key
     * @var string
     */
    protected $pk = 'id';

    /**
     * 字段自动类型转换
     * @var array
     */
    protected $_fieldTypeMap = [];

    /**
     * 数据表废弃字段
     * @var array
     */
    protected $_disuseFields = [];

    /**
     * 数据表只读字段
     * @var array
     */
    protected $_readOnly = [];

    /**
     * 当前模型数据
     * @var array
     */
    protected $_data = [];

    /**
     * 原始数据
     * @var array
     */
    protected $_origin = [];

    /**
     * afterUpdate后可以获取不同属性
     * @var array
     */
    private $_diffAttributes = [];

    /**
     * 修改器执行记录
     * @var array
     */
    private $_set = [];

    /**
     * 获取模型对象的主键
     * @return string
     */
    public function getPk()
    {
        return $this->pk;
    }

    /**
     * 判断一个字段名是否为主键字段
     * @param string $key 名称
     * @return bool
     */
    protected function isPk(string $key): bool
    {
        $pk = $this->getPk();

        if (is_string($pk) && $pk == $key) {
            return true;
        } elseif (is_array($pk) && in_array($key, $pk)) {
            return true;
        }

        return false;
    }

    /**
     * 获取模型对象的主键值
     * @return mixed
     */
    public function getPkValue()
    {
        $pk = $this->getPk();
        if (is_string($pk) && array_key_exists($pk, $this->_data)) {
            $types = $this->getFieldType();
            $pkValue = $this->_data[$pk] ?? 0;
            if (isset($types[$pk]) && !empty($pkValue)) {
                if ($types[$pk] == 'int' || $types[$pk] == 'integer') {
                    $pkValue = (int)$pkValue;
                } else if ($types[$pk] == 'float') {
                    $pkValue = (float)$pkValue;
                } else {
                    $pkValue = (string)$pkValue;
                }
            }
            return $pkValue;
        }
        return 0;
    }

    /**
     * 设置允许写入的字段,默认获取数据表所有字段
     * @param array $fields 允许写入的字段
     * @return $this
     */
    public function allowField(array $fields)
    {
        $this->_tableFields = $fields;
        return $this;
    }

    /**
     * 获取对象原始数据 如果不存在指定字段返回null
     * @param string $fieldName 字段名 留空获取全部
     * @return mixed
     */
    public function getOrigin(string $fieldName = null)
    {
        if (is_null($fieldName)) {
            return $this->_origin;
        }
        return $this->_origin[$fieldName] ?? null;
    }

    /**
     * 获取对象原始数据(原始出表或者对象设置即将入表的数据) 如果不存在指定字段返回null
     * @param string $fieldName 字段名 留空获取全部
     * @return mixed
     */
    public function getData(string $fieldName = null)
    {
        if (is_null($fieldName)) {
            return $this->_data;
        }
        return $this->_data[$fieldName] ?? null;
    }

    /**
     * 获取变化的数据 并排除只读数据
     * @return array
     */
    protected function getChangeData(): array
    {
        $diffData = $this->_force ? $this->_data : $this->parseDiffData();
        return $diffData;
    }

    /**
     * @return array
     */
    protected function parseDiffData()
    {
        $diffData = static::dirtyArray($this->_data, $this->_origin);
        // 只读字段不允许更新
        foreach ($this->_readOnly as $key => $field) {
            if (isset($diffData[$field])) {
                unset($diffData[$field]);
            }
        }

        $originAttributes = $newAttributes = [];
        foreach ($diffData as $fieldName => $value) {
            $originValue = isset($this->_origin[$fieldName]) ? $this->getValue($fieldName, $this->_origin[$fieldName]) : null;
            $originAttributes[$fieldName] = $originValue;
            $newAttributes[$fieldName] = $this->getValue($fieldName, $value);
        }

        if ($originAttributes) {
            $this->_diffAttributes = [
                'old_attributes' => $originAttributes ?? [],
                'new_attributes' => $newAttributes ?? []
            ];
        }

        return $diffData;
    }

    /**
     * @return array
     */
    public function getDiffAttributes()
    {
        if ($this->isNew()) {
            foreach ($this->_data as $field => $value) {
                $newAttributes[$field] = $this->getValue($field, $value);
            }
            $diffAttributes = [
                'old_attributes' => [],
                'new_attributes' => $newAttributes ?? []
            ];
            $this->_diffAttributes = $diffAttributes;
        } else {
            $this->parseDiffData();
            $diffAttributes = $this->_diffAttributes;
        }

        return $diffAttributes;
    }

    /**
     * 获取发生脏变的属性字段
     * @return array
     */
    public function getDirtyAttributeFields()
    {
        $diffAttributes = $this->_diffAttributes;
        if (empty($diffAttributes)) {
            $diffAttributes = $this->getDiffAttributes();
        }
        return array_keys($diffAttributes['new_attributes'] ?? []);
    }

    /**
     * hasDirtyAttributeFields active record 是否有发生变化字段值
     * @return bool
     */
    public function hasDirtyAttributeFields()
    {
        if ($this->isNew()) {
            return true;
        } else {
            $diffAttributes = $this->getDirtyAttributeFields();
            if ($diffAttributes) {
                return true;
            }
            return false;
        }
    }

    /**
     * 获取指定字段更新值
     * @param array $customFields
     * @return array
     */
    protected function getCustomData(array $customFields): array
    {
        $diffData = $originAttributes = $newAttributes = [];
        foreach ($customFields as $fieldName) {
            if (isset($this->_readOnly[$fieldName]) || !isset($this->_data[$fieldName]) || !isset($this->_origin[$fieldName])) {
                continue;
            }
            $diffData[$fieldName] = $this->_data[$fieldName];
            $originAttributes[$fieldName] = $this->getValue($fieldName, $this->_origin[$fieldName]);
            $newAttributes[$fieldName] = $this->getValue($fieldName, $this->_data[$fieldName]);
        }

        return $diffData;
    }

    /**
     * 直接设置数据对象值
     * @param string $name 属性名
     * @param mixed $value 值
     * @return void
     */
    public function set(string $name, $value): void
    {
        $this->_data[$name] = $value;
    }

    /**
     * 数据写入 类型转换。以下类型指的是存在数据库类型
     * @param mixed $value 值
     * @param string|array $type 要转换的类型
     * @return mixed
     */
    protected function writeTransform($value, $type)
    {
        if (is_null($value)) {
            return null;
        }

        if (is_array($type)) {
            [$type, $param] = $type;
        } elseif (strpos($type, ':')) {
            [$type, $param] = explode(':', $type, 2);
        }

        switch ($type) {
            case 'int':
            case 'integer':
                $value = (int)$value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float)$value;
                } else {
                    $value = (float)number_format($value, (int)$param, '.', '');
                }
                break;
            case 'bool':
            case 'boolean':
                $value = (bool)$value;
                break;
            case 'timestamp':
                if (!is_numeric($value)) {
                    $value = strtotime($value);
                }
                break;
            case 'datetime':
                $value = is_numeric($value) ? $value : strtotime($value);
                $value = $this->formatDateTime('Y-m-d H:i:s', $value, true);
                break;
            case 'object':
                if (is_object($value)) {
                    $value = json_encode($value, JSON_FORCE_OBJECT);
                }
                break;
            case 'array':
            case 'json':
                $option = !empty($param) ? (int)$param : JSON_UNESCAPED_UNICODE;
                if (empty($value)) {
                    $value = [];
                }
                if (is_array($value)) {
                    $value = json_encode($value, $option);
                }
                break;
            case 'serialize':
                $value = serialize($value);
                break;
            default:
                if (is_object($value) && false !== strpos($type, '\\') && method_exists($value, '__toString')) {
                    // 对象类型
                    $value = $value->__toString();
                }
        }

        return $value;
    }

    /**
     * 数据读取 类型转换
     * @param mixed $value 值
     * @param string|array $type 要转换的类型
     * @return mixed
     */
    protected function readTransform($value, $type)
    {
        if (is_null($value)) {
            return null;
        }

        if (is_array($type)) {
            [$type, $param] = $type;
        } elseif (strpos($type, ':')) {
            [$type, $param] = explode(':', $type, 2);
        }

        switch ($type) {
            case 'int':
            case 'integer':
                $value = (int)$value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float)$value;
                } else {
                    $value = (float)number_format($value, (int)$param, '.', '');
                }
                break;
            case 'bool':
            case 'boolean':
                $value = (bool)$value;
                break;
            case 'timestamp':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value = $this->formatDateTime($format, $value, true);
                }
                break;
            case 'datetime':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value = $this->formatDateTime($format, $value);
                }
                break;
            case 'json':
            case 'array':
                if (empty($value)) {
                    $value = [];
                }else if (is_string($value)) {
                    $value = json_decode($value, true);
                }
                break;
            case 'object':
                $value = empty($value) ? new \stdClass() : json_decode($value);
                break;
            case 'serialize':
                try {
                    $value = unserialize($value);
                } catch (\Exception $e) {
                    $value = null;
                }
                break;
            default:
                if (false !== strpos($type, '\\')) {
                    $value = new $type($value);
                }
        }

        return $value;
    }
}
