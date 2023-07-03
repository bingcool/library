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

use ArrayAccess;
use Common\Library\Component\ListItemFormatter;
use Common\Library\Exception\DbException;

/**
 * Class Model
 * @package Common\Library\Db
 * @mixin Query
 */
abstract class Model implements ArrayAccess
{
    use Concern\Attribute;
    use Concern\ModelEvent;
    use Concern\Expression;
    use Concern\ParseSql;
    use Concern\TimeStamp;
    use Concern\Util;

    const BEFORE_INSERT = 'BeforeInsert';
    const BEFORE_INSERT_TRANSACTION = 'BeforeInsertTransaction';
    const AFTER_INSERT_TRANSACTION = 'AfterInsertTransaction';
    const AFTER_INSERT = 'AfterInsert';

    const BEFORE_UPDATE = 'BeforeUpdate';
    const BEFORE_UPDATE_TRANSACTION = 'BeforeUpdateTransaction';
    const AFTER_UPDATE_TRANSACTION = 'AfterUpdateTransaction';
    const AFTER_UPDATE = 'AfterUpdate';

    const BEFORE_DELETE = 'BeforeDelete';
    const AFTER_DELETE = 'AfterDelete';

    const EVENTS = [
        self::BEFORE_INSERT,
        self::AFTER_INSERT,
        self::BEFORE_UPDATE,
        self::AFTER_UPDATE,
        self::BEFORE_DELETE,
        self::AFTER_DELETE,
        self::BEFORE_INSERT_TRANSACTION,
        self::AFTER_INSERT_TRANSACTION,
        self::BEFORE_UPDATE_TRANSACTION,
        self::AFTER_UPDATE_TRANSACTION
    ];

    /**
     * @var string
     */
    protected $table;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * @var bool
     */
    protected $isExists = false;

    /**
     * @var bool
     */
    protected $isNew = true;

    /**
     * @var PDOConnection
     */
    protected $connection;

    /**
     * @var int
     */
    protected $_numRows = 0;

    /**
     * @var string
     */
    protected $_suffix = '';

    /**
     * @var array
     */
    protected $_tableFields = [];

    /**
     * @var array
     */
    protected $_schemaInfo = [];

    /**
     * @var array
     */
    protected $_attributes = null;

    /**
     * @var bool
     */
    protected $_force = false;

    /**
     * @var string
     */
    protected $_scene;

    /**
     * @var array
     */
    private $_macro = [];

    /**
     * @var ListItemFormatter
     */
    protected $formatter;

    /**
     * Model constructor.
     * @param mixed ...$params
     */
    public function __construct(...$params)
    {
        $this->init();
    }

    /**
     * 获取当前模型的数据库
     * @return PDOConnection
     */
    abstract public function getConnection();

    /**
     * @param PDOConnection $connection
     */
    public function setConnection(PDOConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return Model
     */
    public static function model(...$params): Model
    {
        return new static(...$params);
    }

    /**
     * 获取当前模型的数据库从库设置
     * @param mixed ...$args
     */
    public function getSlaveConnection(...$args)
    {
        return $this->getConnection();
    }

    /**
     * 自定义创建primary key的值.数据库自增的则忽略该函数处理
     * @return mixed
     */
    public function createPkValue()
    {
    }

    /**
     * @return bool
     */
    protected function onBeforeInsert(): bool
    {
        return true;
    }

    /**
     * return void
     */
    protected function onAfterInsert()
    {
    }

    /**
     * @return bool
     */
    protected function onBeforeUpdate(): bool
    {
        return true;
    }

    /**
     * @return void
     */
    protected function onAfterUpdate()
    {
    }

    /**
     * @return bool
     */
    protected function onBeforeDelete(): bool
    {
        return true;
    }

    /**
     * @return void
     */
    protected function onAfterDelete()
    {
    }

    protected function init()
    {
    }

    protected function checkData()
    {
    }

    protected function checkResult($result)
    {
    }

    /**
     * 设置数据是否存在
     * @param bool $exists
     * @return $this
     */
    protected function exists(bool $exists = true)
    {
        $this->isExists = $exists;
        return $this;
    }

    /**
     * 判断数据是否存在数据库
     * @return bool
     */
    public function isExists(): bool
    {
        return $this->isExists;
    }

    /**
     * @param bool $isNew
     */
    protected function setIsNew(bool $isNew): void
    {
        $this->isNew = $isNew;
    }

    /**
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * @return int
     */
    public function getNumRows(): int
    {
        return $this->_numRows;
    }

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * @return Query
     */
    public function newQuery(): Query
    {
        if (method_exists($this->getConnection(), 'getObject')) {
            $query = new Query($this->getConnection()->getObject());
        }else {
            $query = new Query($this->getConnection());
        }
        return $query;
    }

    /**
     * @return array
     */
    protected function getSchemaInfo(): array
    {
        if (empty($this->_schemaInfo)) {
            $table = $this->table ? $this->table . $this->_suffix : $this->table;
            $schemaInfo = $this->getConnection()->getSchemaInfo($table);
            $this->_schemaInfo = $schemaInfo;
        }

        return $this->_schemaInfo;
    }

    /**
     * @param \Closure $callback
     * @return mixed|null
     * @throws \Throwable
     */
    protected function transaction(\Closure $callback)
    {
        $result = null;
        if($this->getConnection()->isEnableTransaction()) {
            $result = $callback->call($this);
            return $result;
        }else {
            try {
                $this->getConnection()->beginTransaction();
                $result = $callback->call($this);
                $this->getConnection()->commit();
                return $result;
            } catch (\Exception | \Throwable $e) {
                $this->getConnection()->rollback();
                throw $e;
            }
        }
    }

    /**
     * $this
     */
    public function beginTransaction()
    {
        if(!$this->getConnection()->isEnableTransaction()) {
            $this->getConnection()->beginTransaction();
        }
        return $this;
    }

    /**
     * 修改器 设置数据对象的值处理
     * @param string $name 名称
     * @param mixed $value 值
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * @param string $name
     * @param $value
     * @return void
     */
    public function setAttribute(string $name, $value): void
    {
        if ($this->isExists() && $name == $this->getPk()) {
            return;
        }

        $method = 'set' . self::studly($name) . 'Attr';

        if (method_exists(static::class, $method)) {
            // 返回修改器处理过的数据
            $value = $this->$method($value);
            $this->_set[$name] = true;
            if (is_null($value)) {
                return;
            }
        } else if (isset($this->_fieldTypeMap[$name])) {
            //类型转换
            $value = $this->writeTransform($value, $this->_fieldTypeMap[$name]);
        }
        // 源数据
        if (!$this->isExists()) {
            $this->_origin[$name] = $value;
        }

        // 设置数据对象属性
        $this->_data[$name] = $value;
    }

    /**
     * 保存当前数据对象
     * @param array $data 数据
     * @return bool
     */
    public function save(): bool
    {
        $result = $this->isExists() ? $this->updateData() : $this->insertData();
        if (false === $result) {
            return false;
        }
        // 重新记录原始数据
        $this->_origin = $this->_data;
        $this->_set = [];
        return true;
    }

    /**
     * 新增写入数据
     * @return bool
     * @throws \Throwable
     */
    protected function insertData()
    {
        // new flag
        $this->setIsNew(true);

        if (false === $this->trigger('BeforeInsert')) {
            return false;
        }

        $this->checkData();

        $allowFields = $this->getAllowFields();
        $pk = $this->getPk();
        // define increment primary key
        if (!isset($this->_data[$pk])) {
            $pkValue = $this->createPkValue();
            $pkValue && $this->_data[$pk] = $pkValue;
        } else {
            // 数据表设置自增pk的，则不需要设置允许字段
            $allowFields = array_diff($allowFields, [$pk]);
        }

        list($sql, $bindParams) = $this->parseInsertSql($allowFields);

        try {

            $hasBeforeInsertTransaction = method_exists(static::class, 'onBeforeInsertTransaction');
            $hasAfterInsertTransaction = method_exists(static::class, 'onAfterInsertTransaction');
            $enableAfterCommitCallback = false;
            if($hasBeforeInsertTransaction || $hasAfterInsertTransaction || $this->getConnection()->isEnableTransaction()) {
                if(method_exists(static::class, 'onAfterInsertCommitCallBack')) {
                    $enableAfterCommitCallback = true;
                    $this->getConnection()->afterCommitCallback([$this,'onAfterInsertCommitCallBack']);
                }
            }

            if ($hasBeforeInsertTransaction && $hasAfterInsertTransaction) {
                $this->transaction(function () use ($sql, $bindParams) {
                    $this->onBeforeInsertTransaction();
                    $this->_numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
                    $this->onAfterInsertTransaction();
                    // set exist
                    $this->exists(true);
                    // query buildAttributes
                    $this->buildAttributes();
                    $this->trigger('AfterInsert');
                });
            } else if ($hasBeforeInsertTransaction) {
                $this->transaction(function () use ($sql, $bindParams) {
                    $this->onBeforeInsertTransaction();
                    $this->_numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
                    // set exist
                    $this->exists(true);
                    // query buildAttributes
                    $this->buildAttributes();
                    $this->trigger('AfterInsert');
                });
            } else if ($hasAfterInsertTransaction) {
                $this->transaction(function () use ($sql, $bindParams) {
                    $this->_numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
                    $this->onAfterInsertTransaction();
                    // set exist
                    $this->exists(true);
                    // query buildAttributes
                    $this->buildAttributes();
                    $this->trigger('AfterInsert');
                });
            } else {
                $this->_numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
                // set exist
                $this->exists(true);
                // query buildAttributes
                $this->buildAttributes();
                $this->trigger('AfterInsert');
            }
        } catch (\Throwable $e) {
            $this->_numRows = 0;
            throw $e;
        }

        // if increment primary key insert successful set primary key to data array
        if (!isset($this->_data[$pk]) || is_null($this->_data[$pk]) || $this->_data[$pk] == '') {
            $this->_data[$pk] = $this->getConnection()->getLastInsID($pk);
        }

        if(!$enableAfterCommitCallback) {
            if(method_exists(static::class, 'onAfterInsertCommitCallBack')) {
                $this->onAfterInsertCommitCallBack();
            }
        }

        return $this->_data[$pk] ?? '';
    }

    /**
     * @return int
     */
    public function getLastInsertId(): ?int
    {
        if ($this->isNew() && $this->isExists()) {
            return $this->getPkValue();
        }
        return null;
    }

    /**
     * 检查数据是否允许写入
     * @return array
     */
    protected function getAllowFields(): array
    {
        if (empty($this->_tableFields)) {
            $schemaInfo = $this->getSchemaInfo();
            $fields = $schemaInfo['fields'];
            if (!empty($this->_disuseFields)) {
                $fields = array_diff($fields, $this->_disuseFields);
            }
            $this->_tableFields = $fields;
        }

        return $this->_tableFields;
    }

    /**
     * buildAttributes
     * @return $this|bool
     */
    protected function buildAttributes()
    {
        list($sql, $bindParams) = $this->parseFindSqlByPk();
        $attributes = $this->getConnection()->createCommand($sql)->findOne($bindParams);
        if ($attributes) {
            $this->parseOrigin($attributes);
            return $this;
        }
    }

    /**
     * 保存写入更新数据
     * @param array $attributes
     * @return bool
     * @throws \Throwable
     */
    protected function updateData(array $attributes = []): bool
    {
        $this->setIsNew(false);
        if (false === $this->trigger('BeforeUpdate')) {
            return false;
        }

        $this->checkData();
        if (!$attributes) {
            // auto get change fields
            $diffData = $this->getChangeData();
        } else {
            // specify update fields
            $diffData = $this->getCustomData($attributes);
        }

        $allowFields = $this->getAllowFields();

        if ($diffData || count($this->expressionFields) > 0) {
            list($sql, $bindParams) = $this->parseUpdateSql($diffData, $allowFields);

            $hasBeforeUpdateTransaction = method_exists(static::class, 'onBeforeUpdateTransaction');
            $hasAfterUpdateTransaction  = method_exists(static::class, 'onAfterUpdateTransaction');

            $enableAfterCommitCallback = false;
            if($hasBeforeUpdateTransaction || $hasAfterUpdateTransaction || $this->getConnection()->isEnableTransaction()) {
                if(method_exists(static::class, 'onAfterUpdateCommitCallBack')) {
                    $enableAfterCommitCallback = true;
                    $this->getConnection()->afterCommitCallback([$this,'onAfterUpdateCommitCallBack']);
                }
            }

            try {
                if ($hasBeforeUpdateTransaction && $hasAfterUpdateTransaction) {
                    $this->transaction(function () use ($sql, $bindParams) {
                        $this->onBeforeUpdateTransaction();
                        $this->_numRows = $this->getConnection()->createCommand($sql)->update($bindParams);
                        $this->onAfterUpdateTransaction();
                        $this->checkResult($this->_data);
                        $this->trigger('AfterUpdate');
                    });
                } else if ($hasBeforeUpdateTransaction) {
                    $this->transaction(function () use ($sql, $bindParams) {
                        $this->onBeforeUpdateTransaction();
                        $this->_numRows = $this->getConnection()->createCommand($sql)->update($bindParams);
                        $this->checkResult($this->_data);
                        $this->trigger('AfterUpdate');
                    });
                } else if ($hasAfterUpdateTransaction) {
                    $this->transaction(function () use ($sql, $bindParams) {
                        $this->_numRows = $this->getConnection()->createCommand($sql)->update($bindParams);
                        $this->onAfterUpdateTransaction();
                        $this->checkResult($this->_data);
                        $this->trigger('AfterUpdate');
                    });
                } else {
                    $this->_numRows = $this->getConnection()->createCommand($sql)->update($bindParams);
                    $this->checkResult($this->_data);
                    $this->trigger('AfterUpdate');
                }

            } catch (\Throwable $e) {
                $this->_numRows = 0;
                throw $e;
            }

            if(!$enableAfterCommitCallback) {
                if(method_exists(static::class, 'onAfterUpdateCommitCallBack')) {
                    $this->onAfterUpdateCommitCallBack();
                }
            }
        }

        return true;
    }

    /**
     * 指定字段更新
     * @param array $attributes
     * @return bool
     */
    public function update(array $attributes): bool
    {
        $this->_force = false;
        return $this->updateData($attributes);
    }

    /**
     * @param bool $force 强制物理删除
     * @return bool
     */
    public function delete(bool $force = false): bool
    {
        if (!$this->isExists()) {
            throw new DbException('Active object is not exist');
        }

        $this->setIsNew(false);

        if (!$this->isExists || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        if ($force) {
            list($sql, $bindParams) = $this->parseDeleteSql();
            $this->_numRows = $this->getConnection()->createCommand($sql)->delete($bindParams);
        } else {
            if ($this->processDelete() === false) {
                throw new DbException('ProcessDelete Failed');
            }
        }

        $this->exists(false);
        $this->trigger('AfterDelete');

        if($this->getConnection()->isEnableTransaction()) {
            if(method_exists(static::class, 'onAfterDeleteCommitCallBack')) {
                $this->getConnection()->afterCommitCallback([$this,'onAfterDeleteCommitCallBack']);
            }
        }else {
            if(method_exists(static::class, 'onAfterDeleteCommitCallBack')) {
                $this->onAfterDeleteCommitCallBack();
            }
        }

        return true;
    }

    /**
     * 自定义逻辑删除过程
     * @return bool
     */
    protected function processDelete(): bool
    {
        //todo
        // 逻辑删除，可能不需要再次执行update操作
        $this->skipEvent(self::BEFORE_UPDATE);
        $this->skipEvent(self::AFTER_UPDATE);
        return true;
    }

    /**
     * @return string
     */
    public function getScene()
    {
        return $this->_scene;
    }

    /**
     * @param string $value
     */
    public function setScene(string $value)
    {
        $this->_scene = $value;
    }

    /**
     * 当前对象的迭代器
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->getAttributes());
    }

    /**
     * 检测数据对象的值
     * @param string $name 名称
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return !is_null($this->getAttribute($name));
    }

    /**
     * 获取器 获取数据对象的值
     * @param string $name 名称
     * @return mixed
     * @throws \Exception
     */
    public function __get(string $name)
    {
        return $this->getAttribute($name);
    }

    /**
     * 获取器 获取当前数据对象的值
     * @param string $fieldName
     * @return mixed
     */
    public function getAttribute(string $fieldName)
    {
        $value = $this->getData($fieldName);
        return $this->getValue($fieldName, $value);
    }

    /**
     * 获取存在记录的字段旧值(原生数据库直接读取的值，还没经过format处理的值)
     * @param string $fieldName
     * @param bool $format
     * @return mixed
     */
    public function getOldAttributeValue(string $fieldName, bool $format = false)
    {
        if (!$this->isNew()) {
            if ($format) {
                $value = $this->getOrigin($fieldName);
                if (!is_null($value)) {
                    $value = $this->getValue($fieldName, $value);
                }
            } else {
                $value = $this->_origin[$fieldName] ?? null;
            }
        }

        return $value ?? null;
    }

    /**
     * 获取当前对象设置字段最新值(即将要存进数据库的值)
     * @param string $fieldName
     * @param bool $format
     * @return string|null
     */
    public function getNewAttributeValue(string $fieldName, bool $format = false)
    {
        if ($format) {
            return $this->getAttribute($fieldName);
        }
        return $this->_data[$fieldName] ?? null;
    }

    /**
     * 字段发生脏值变化,可以用于更新某些状态值时触发事件
     * @param string $fieldName
     * @return bool
     */
    public function isDirty(string $fieldName): bool
    {
        if (in_array($fieldName, $this->getAllowFields())) {
            if ($this->getOldAttributeValue($fieldName) != $this->getNewAttributeValue($fieldName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $fieldName
     * @param $value
     * @return mixed
     */
    protected function getValue(string $fieldName, $value)
    {
        $method = 'get' . self::studly($fieldName) . 'Attr';
        if (method_exists(static::class, $method)) {
            $value = $this->$method($value);
        } else if (isset($this->_fieldTypeMap[$fieldName])) {
            $value = $this->readTransform($value, $this->_fieldTypeMap[$fieldName]);
        }
        return $value;
    }

    /**
     * 获取当前对象经过属性的getter函数处理后的业务目标数据
     * @return array
     */
    public function getAttributes(): array
    {
        $attributes = [];
        if ($this->_data) {
            foreach ($this->_data as $fieldName => $value) {
                if (in_array($fieldName, $this->getAllowFields())) {
                    $attributes[$fieldName] = $this->getValue($fieldName, $value);
                } else {
                    unset($this->_data[$fieldName]);
                }
            }
        }

        // formater
        if (is_object($this->formatter) && $this->formatter instanceof ListItemFormatter) {
            $this->formatter->setData($attributes);
            $attributes = $this->formatter->result();
        }

        return $attributes;
    }

    /**
     * 获取当前对象存在的record经过属性的getter函数处理后的业务真实目标数据(存在数据)
     *
     * @return array
     */
    public function getOldAttributes(): array
    {
        if ($this->isExists() && $this->_origin) {
            foreach ($this->_origin as $fieldName => $value) {
                if (in_array($fieldName, $this->getAllowFields())) {
                    $attributes[$fieldName] = $this->getValue($fieldName, $value);
                } else {
                    unset($this->_origin[$fieldName]);
                }
            }
        }
        return $attributes ?? [];
    }

    /**
     * 判断模型是否为空
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->_data);
    }

    /**
     * 设置当前模型数据表的后缀
     * @param string $suffix 数据表后缀
     * @return $this
     */
    public function setSuffix(string $suffix)
    {
        $this->_suffix = $suffix;
        return $this;
    }

    /**
     * 获取当前模型的数据表后缀
     * @return string
     */
    public function getSuffix(): string
    {
        return $this->_suffix ?: '';
    }

    /**
     * 切换后缀进行查询
     * @param string $suffix 切换的表后缀
     * @return Model
     */
    public static function modelSuffix(string $suffix)
    {
        $model = new static();
        $model->setSuffix($suffix);
        return $model;
    }

    /**
     * 设置方法注入
     * @access public
     * @param string $method
     * @param \Closure $closure
     * @return void
     */
    public function macroMethod(string $method, \Closure $closure)
    {
        if (!isset($this->_macro[static::class])) {
            $this->_macro[static::class] = [];
        }
        $this->_macro[static::class][$method] = $closure;
    }

    /**
     * @param ListItemFormatter $formatter
     * @return $this
     */
    public function setFormatter(ListItemFormatter $formatter)
    {
        $this->formatter = $formatter;
        return $this;
    }

    /**
     * 调用注入绑定的method
     *
     * @param $method
     * @param $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if (isset($this->_macro[static::class][$method])) {
            $closure = $this->_macro[static::class][$method];
            if ($closure instanceof \Closure) {
                return $closure->call($this, ...$arguments);
            }
        }

        if(in_array($method,['onAfterInsertCommitCallBack', 'onAfterUpdateCommitCallBack','onAfterDeleteCommitCallBack'])) {
            return $this->$method(...$arguments);
        }
    }

    /**
     * @param $method
     * @param $arguments
     * @return Query
     */
    public static function __callStatic($method, $arguments)
    {
        $entity = new static();
        if (method_exists($entity->getConnection(), 'getObject')) {
            $query = (new Query($entity->getConnection()->getObject()))->table($entity->getTableName())->{$method}(...$arguments);
        }else {
            $query = (new Query($entity->getConnection()))->table($entity->getTableName())->{$method}(...$arguments);
        }
        return $query;
    }

    /**
     * 销毁数据对象的值
     * @param string $name
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->_data[$name]);
    }

    // ArrayAccess
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return $this->__isset($offset);
    }

    /**
     * @param mixed $offset
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        $this->__unset($offset);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getAttribute($offset);
    }

    /**
     * 转换当前模型对象源数据转为JSON字符串
     * @param int $options json参数
     * @return string
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->_data, $options);
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->_data;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * __destruct
     */
    public function __destruct()
    {

    }
}