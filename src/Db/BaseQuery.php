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

use Common\Library\Db\Helper\Str;
use Common\Library\Db\Concern;
use Common\Library\Exception\DbException;

/**
 * 数据查询基础类
 */

abstract class BaseQuery
{

    use Concern\WhereQuery;
    use Concern\AggregateQuery;
    use Concern\Transaction;
    use Concern\ResultOperation;

    /**
     * 当前数据库连接对象
     * @var PDOConnection
     */
    protected $connection;

    /**
     * 当前数据表名称（不含前缀）
     * @var string
     */
    protected $name = '';

    /**
     * 当前数据表主键
     * @var string|array
     */
    protected $pk;

    /**
     * 当前数据表自增主键
     * @var string
     */
    protected $autoinc;

    /**
     * 当前数据表前缀
     * @var string
     */
    protected $prefix = '';

    /**
     * 当前查询参数
     * @var array
     */
    protected $options = [];
    /**
     * @var \Common\Library\Db\AbstractBuilder
     */
    protected $builder;

    /**
     * 架构函数
     * @access public
     * @param ConnectionInterface $connection 数据库连接对象
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        $this->prefix = $this->connection->getConfig('prefix');

        $class = $this->parseBuilderClass();
        $this->builder = new $class($connection);
    }

    /**
     * @return AbstractBuilder
     */
    public function getBuilder(): AbstractBuilder
    {
        return $this->builder;
    }

    /**
     * @return mixed|string
     */
    protected function parseBuilderClass()
    {
        $config = $this->connection->getConfig('type');
        $type = !empty($config['type']) ? $config['type'] : 'mysql';
        if (false !== strpos($type, '\\')) {
            $class = $type;
        } else {
            $class = '\\Common\\Library\\Db\\Builder\\' . ucfirst($type);
        }

        return $class;
    }

    /**
     * 利用__call方法实现一些特殊的Model方法
     * @access public
     * @param string $method 方法名称
     * @param array  $args   调用参数
     * @return mixed
     * @throws DbException
     */
    public function __call(string $method, array $args)
    {
        if (strtolower(substr($method, 0, 5)) == 'getby') {
            // 根据某个字段获取记录
            $field = Str::snake(substr($method, 5));
            return $this->where($field, '=', $args[0])->find();
        } elseif (strtolower(substr($method, 0, 10)) == 'getfieldby') {
            // 根据某个字段获取记录的某个值
            $name = Str::snake(substr($method, 10));
            return $this->where($name, '=', $args[0])->value($args[1]);
        } elseif (strtolower(substr($method, 0, 7)) == 'whereor') {
            $name = Str::snake(substr($method, 7));
            array_unshift($args, $name);
            return call_user_func_array([$this, 'whereOr'], $args);
        } elseif (strtolower(substr($method, 0, 5)) == 'where') {
            $name = Str::snake(substr($method, 5));
            array_unshift($args, $name);
            return call_user_func_array([$this, 'where'], $args);
        } else {
            throw new DbException('method not exist:' . static::class . '->' . $method);
        }
    }

    /**
     * 创建一个新的查询对象
     * @access public
     * @return BaseQuery
     */
    public function newQuery(): BaseQuery
    {
        $query = new static($this->connection);

        if (isset($this->options['table'])) {
            $query->table($this->options['table']);
        } else {
            $query->name($this->name);
        }

        if (!empty($this->options['json'])) {
            $query->json($this->options['json'], $this->options['json_assoc']);
        }

        if (isset($this->options['field_type'])) {
            $query->setFieldType($this->options['field_type']);
        }

        return $query;
    }

    /**
     * 获取当前的数据库Connection对象
     * @access public
     * @return ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * 指定当前数据表名（不含前缀）
     * @access public
     * @param string $name 不含前缀的数据表名字
     * @return $this
     */
    public function name(string $name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获取当前的数据表名称
     * @access public
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取数据库的配置参数
     * @access public
     * @param string $name 参数名称
     * @return mixed
     */
    public function getConfig(string $name = '')
    {
        return $this->connection->getConfig($name);
    }

    /**
     * 得到当前或者指定名称的数据表
     * @access public
     * @param string $name 不含前缀的数据表名字
     * @return mixed
     */
    public function getTable(string $name = '')
    {
        if (empty($name) && isset($this->options['table'])) {
            return $this->options['table'];
        }

        $name = $name ?: $this->name;

        return $this->prefix . Str::snake($name);
    }

    /**
     * 设置字段类型信息
     * @access public
     * @param array $type 字段类型信息
     * @return $this
     */
    public function setFieldType(array $type)
    {
        $this->options['field_type'] = $type;
        return $this;
    }

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql(): string
    {
        return $this->connection->getLastSql();
    }

    /**
     * 获取返回或者影响的记录数
     * @access public
     * @return integer
     */
    public function getNumRows(): int
    {
        return $this->connection->getNumRows();
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param string $sequence 自增序列名
     * @return mixed
     */
    public function getLastInsID(string $sequence = null)
    {
        return $this->connection->getLastInsID($this, $sequence);
    }

    /**
     * 得到某个字段的值
     * @access public
     * @param string $field   字段名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function value(string $field, $default = null)
    {
        $options = $this->parseOptions();

        if (isset($options['field'])) {
            $this->removeOption('field');
        }

        if (isset($options['group'])) {
            $this->group('');
        }

        $this->setOption('field', (array) $field);

        // 生成查询SQL
        $sql = $this->builder->select($this, true);

        if (isset($options['field'])) {
            $this->setOption('field', $options['field']);
        } else {
            $this->removeOption('field');
        }

        if (isset($options['group'])) {
            $this->setOption('group', $options['group']);
        }

        $result = $this->connection->PDOStatementHandle($sql, $this->getBind())->fetchColumn();

       return (false !== $result) ? $result : $default;
    }

    /**
     * 得到某个列的数组
     * @access public
     * @param string|array $column 字段名 多个字段用逗号分隔
     * @param string       $key   索引
     * @return array
     */
    public function column($column, string $key = ''): array
    {
        $options = $this->parseOptions();

        if (isset($options['field'])) {
            $this->removeOption('field');
        }

        if (empty($key) || trim($key) === '') {
            $key = null;
        }

        if (is_string($column)) {
            $column = trim($column);
            if ('*' !== $column) {
                $column = array_map('trim', explode(',', $column));
            }
        } elseif (is_array($column)) {
            if (in_array('*', $column)) {
                $column = '*';
            }
        } else {
            throw new DbException('not support type');
        }

        $field = $column;
        if ('*' !== $column && $key && !in_array($key, $column)) {
            $field[] = $key;
        }

        $this->setOption('field', $field);

        // 生成查询SQL
        $sql = $this->builder->select($this);

        if (isset($options['field'])) {
            $this->setOption('field', $options['field']);
        } else {
            $this->removeOption('field');
        }

        // 执行查询操作
        $resultSet = $this->connection->query($sql, $this->getBind(), \PDO::FETCH_ASSOC);

        if (is_string($key) && strpos($key, '.')) {
            [$alias, $key] = explode('.', $key);
        }

        if (empty($resultSet)) {
            $result = [];
        } elseif ('*' !== $column && count($column) === 1) {
            $column = array_shift($column);
            if (strpos($column, ' ')) {
                $column = substr(strrchr(trim($column), ' '), 1);
            }

            if (strpos($column, '.')) {
                [$alias, $column] = explode('.', $column);
            }

            if (strpos($column, '->')) {
                $column = $this->builder->parseKey($this, $column);
            }

            $result = array_column($resultSet, $column, $key);
        } elseif ($key) {
            $result = array_column($resultSet, null, $key);
        } else {
            $result = $resultSet;
        }

        return $result;
    }

    /**
     * 查询SQL组装 union
     * @access public
     * @param mixed   $union UNION
     * @param boolean $all   是否适用UNION ALL
     * @return $this
     */
    public function union($union, bool $all = false)
    {
        $this->options['union']['type'] = $all ? 'UNION ALL' : 'UNION';

        if (is_array($union)) {
            $this->options['union'] = array_merge($this->options['union'], $union);
        } else {
            $this->options['union'][] = $union;
        }

        return $this;
    }

    /**
     * 查询SQL组装 union all
     * @access public
     * @param mixed $union UNION数据
     * @return $this
     */
    public function unionAll($union)
    {
        return $this->union($union, true);
    }

    /**
     * 指定查询字段
     * @access public
     * @param mixed $field 字段信息
     * @return $this
     */
    public function field($field)
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Raw) {
            $this->options['field'][] = $field;
            return $this;
        }

        if (is_string($field) && $field != '*') {
            if (preg_match('/[\<\'\"\(]/', $field)) {
                return $this->fieldRaw($field);
            }

            $field = array_map('trim', explode(',', $field));
        }

        if ($field == '*') {
            $field  = (array) $field;
        }

        if (isset($this->options['field'])) {
            $field = array_merge((array) $this->options['field'], $field);
        }

        $this->options['field'] = array_unique($field, SORT_REGULAR);

        return $this;
    }

    /**
     * 指定要排除的查询字段
     * @access public
     * @param array|string $field 要排除的字段
     * @return $this
     */
    public function withoutField($field)
    {
        if (empty($field)) {
            return $this;
        }

        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }

        // 字段排除
        $fields = $this->getTableFields();
        $field  = $fields ? array_diff($fields, $field) : $field;

        if (isset($this->options['field'])) {
            $field = array_merge((array) $this->options['field'], $field);
        }

        $this->options['field'] = array_unique($field, SORT_REGULAR);

        return $this;
    }

    /**
     * 指定其它数据表的查询字段
     * @access public
     * @param mixed   $field     字段信息
     * @param string  $tableName 数据表名
     * @param string  $prefix    字段前缀
     * @param string  $alias     别名前缀
     * @return $this
     */
    public function tableField($field, string $tableName, string $prefix = '', string $alias = '')
    {
        if (empty($field)) {
            return $this;
        }

        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }

        if (true === $field) {
            // 获取全部字段
            $fields = $this->getTableFields($tableName);
            $field  = $fields ?: ['*'];
        }

        // 添加统一的前缀
        $prefix = $prefix ?: $tableName;
        foreach ($field as $key => &$val) {
            if (is_numeric($key) && $alias) {
                $field[$prefix . '.' . $val] = $alias . $val;
                unset($field[$key]);
            } elseif (is_numeric($key)) {
                $val = $prefix . '.' . $val;
            }
        }

        if (isset($this->options['field'])) {
            $field = array_merge((array) $this->options['field'], $field);
        }

        $this->options['field'] = array_unique($field, SORT_REGULAR);

        return $this;
    }

    /**
     * 设置数据
     * @access public
     * @param array $data 数据
     * @return $this
     */
    public function data(array $data)
    {
        $this->options['data'] = $data;

        return $this;
    }

    /**
     * 去除查询参数
     * @access public
     * @param string $option 参数名 留空去除所有参数
     * @return $this
     */
    public function removeOption(string $option = '')
    {
        if ('' === $option) {
            $this->options = [];
            $this->bind    = [];
        } elseif (isset($this->options[$option])) {
            unset($this->options[$option]);
        }

        return $this;
    }

    /**
     * 指定查询数量
     * @access public
     * @param int $offset 起始位置
     * @param int $length 查询数量
     * @return $this
     */
    public function limit(int $offset, int $length = null)
    {
        $this->options['limit'] = $offset . ($length ? ',' . $length : '');

        return $this;
    }

    /**
     * 指定分页
     * @access public
     * @param int $page     页数
     * @param int $listRows 每页数量
     * @return $this
     */
    public function page(int $page, int $listRows = null)
    {
        $this->options['page'] = [$page, $listRows];

        return $this;
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function table($table)
    {
        if (is_string($table)) {
            if (strpos($table, ')')) {
                // 子查询
            } elseif (false === strpos($table, ',')) {
                if (stripos($table, ' as ') || strpos($table, ' ')) {
                    $items = explode(' ', $table);
                    $item = $items[0];
                    $table = [];
                    $alias = array_pop($items);
                    $this->alias([$item => $alias]);
                    $table[$item] = $alias;
                }
            } else {
                $tables = explode(',', $table);
                $table  = [];

                foreach ($tables as $tableItem) {
                    $tableItem = trim($tableItem);
                    if (stripos($tableItem, ' as ') || strpos($tableItem, ' ')) {
                        $items = explode(' ', $tableItem);
                        $item = $items[0];
                        $alias = array_pop($items);
                        $this->alias([$item => $alias]);
                        $table[$item] = $alias;
                    } else {
                        $table[] = $tableItem;
                    }
                }
            }
        } elseif (is_array($table)) {
            $tables = $table;
            $table  = [];

            foreach ($tables as $key => $val) {
                if (is_numeric($key)) {
                    $table[] = $val;
                } else {
                    $this->alias([$key => $val]);
                    $table[$key] = $val;
                }
            }
        }

        $this->options['table'] = $table;

        return $this;
    }

    /**
     * 指定排序 order('id','desc') 或者 order(['id'=>'desc','create_time'=>'desc'])
     * @access public
     * @param string|array|Raw $field 排序字段
     * @param string           $order 排序
     * @return $this
     */
    public function order($field, string $order = '')
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Raw) {
            $this->options['order'][] = $field;
            return $this;
        }

        if (is_string($field)) {
            if (!empty($this->options['via'])) {
                $field = $this->options['via'] . '.' . $field;
            }
            if (strpos($field, ',')) {
                $field = array_map('trim', explode(',', $field));
            } else {
                $field = empty($order) ? $field : [$field => $order];
            }
        } elseif (!empty($this->options['via'])) {
            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $field[$key] = $this->options['via'] . '.' . $val;
                } else {
                    $field[$this->options['via'] . '.' . $key] = $val;
                    unset($field[$key]);
                }
            }
        }

        if (!isset($this->options['order'])) {
            $this->options['order'] = [];
        }

        if (is_array($field)) {
            $this->options['order'] = array_merge($this->options['order'], $field);
        } else {
            $this->options['order'][] = $field;
        }

        return $this;
    }

    /**
     * 指定数据表别名
     * @access public
     * @param array|string $alias 数据表别名
     * @return $this
     */
    public function alias($alias)
    {
        if (is_array($alias)) {
            $this->options['alias'] = $alias;
        } else {
            $table = $this->getTable();

            $this->options['alias'][$table] = $alias;
        }

        return $this;
    }


    /**
     * 设置是否严格检查字段名
     * @access public
     * @param bool $strict 是否严格检查字段
     * @return $this
     */
    public function strict(bool $strict = true)
    {
        $this->options['strict'] = $strict;
        return $this;
    }

    /**
     * 设置自增序列名
     * @access public
     * @param string $sequence 自增序列名
     * @return $this
     */
    public function sequence(string $sequence = null)
    {
        $this->options['sequence'] = $sequence;
        return $this;
    }

    /**
     * 设置JSON字段信息
     * @access public
     * @param array $json  JSON字段
     * @param bool  $assoc 是否取出数组
     * @return $this
     */
    public function json(array $json = [], bool $assoc = false)
    {
        $this->options['json']       = $json;
        $this->options['json_assoc'] = $assoc;

        return $this;
    }

    /**
     * 指定数据表主键
     * @access public
     * @param string|array $pk 主键
     * @return $this
     */
    public function pk($pk)
    {
        $this->pk = $pk;
        return $this;
    }

    /**
     * 查询参数批量赋值
     * @access protected
     * @param array $options 表达式参数
     * @return $this
     */
    protected function options(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * 获取当前的查询参数
     * @access public
     * @param string $name 参数名
     * @return mixed
     */
    public function getOptions(string $name = '')
    {
        if ('' === $name) {
            return $this->options;
        }

        return $this->options[$name] ?? null;
    }

    /**
     * 设置当前的查询参数
     * @access public
     * @param string $option 参数名
     * @param mixed  $value  参数值
     * @return $this
     */
    public function setOption(string $option, $value)
    {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * 设置当前字段添加的表别名
     * @access public
     * @param string $via 临时表别名
     * @return $this
     */
    public function via(string $via = '')
    {
        $this->options['via'] = $via;

        return $this;
    }

    /**
     * 保存记录 自动判断insert或者update
     * @access public
     * @param array $data        数据
     * @param bool  $forceInsert 是否强制insert
     * @return integer
     */
    public function save(array $data = [], bool $forceInsert = false)
    {
        if ($forceInsert) {
            return $this->insert($data);
        }

        $this->options['data'] = array_merge($this->options['data'] ?? [], $data);

        if (!empty($this->options['where'])) {
            $isUpdate = true;
        } else {
            $isUpdate = $this->parseUpdateData($this->options['data']);
        }

        return $isUpdate ? $this->update() : $this->insert();
    }

    /**
     * 插入记录
     * @access public
     * @param array   $data         数据
     * @param boolean $getLastInsID 返回自增主键
     * @return integer|string
     */
    public function insert(array $data = [], bool $getLastInsID = true)
    {
        if (!empty($data)) {
            $this->options['data'] = $data;
        }

        $this->parseOptions();
        $sql = $this->builder->insert($this);
        $bindParams = $this->getBind();
        $this->connection->createCommand($sql)->insert($bindParams);

        if ($getLastInsID) {
            $lastInsID = $this->connection->getLastInsID();
        }

        return $lastInsID ?? '';
    }

    /**
     * 插入记录并获取自增ID
     * @access public
     * @param array $data 数据
     * @return integer|string
     */
    public function insertGetId(array $data)
    {
        return $this->insert($data, true);
    }

    /**
     * 批量插入记录
     * @access public
     * @param array   $dataSet 数据集
     * @param integer $limit   每次写入数据限制
     * @return integer
     */
    public function insertAll(array $dataSet = [], int $limit = 0): int
    {
        if (empty($dataSet)) {
            $dataSet = $this->options['data'] ?? [];
        }

        if (empty($limit) && !empty($this->options['limit']) && is_numeric($this->options['limit'])) {
            $limit = (int) $this->options['limit'];
        }

        $this->parseOptions();

        if (!is_array(reset($dataSet))) {
            return 0;
        }

        if (0 === $limit && count($dataSet) >= 5000) {
            $limit = 1000;
        }

        if ($limit) {
            // 分批写入 自动启动事务支持
            $this->connection->beginTransaction();

            try {
                $array = array_chunk($dataSet, $limit, true);
                $count = 0;

                foreach ($array as $item) {
                    $sql = $this->builder->insertAll($this, $item);
                    $bindParams = $this->getBind();
                    $count += $this->connection->createCommand($sql)->execute($sql, $bindParams);
                }

                // 提交事务
                $this->connection->commit();
            } catch (\Exception | \Throwable $e) {
                $this->connection->rollback();
                throw $e;
            }

            return $count;
        }

        $sql = $this->builder->insertAll($this, $dataSet);
        $bindParams = $this->getBind();
        return $this->connection->createCommand($sql)->execute($sql, $bindParams);
    }

    /**
     * 通过Select方式插入记录
     * @access public
     * @param array  $fields 要插入的数据表字段名
     * @param string $table  要插入的数据表名
     * @return integer
     */
    public function selectInsert(array $fields, string $table): int
    {
        $this->parseOptions();
        $sql = $this->builder->selectInsert($this, $fields, $table);
        $bindParams = $this->getBind();
        return $this->connection->createCommand($sql)->execute($sql, $bindParams);
    }

    /**
     * 更新记录
     * @access public
     * @param mixed $data 数据
     * @return integer
     * @throws DbException
     */
    public function update(array $data = []): int
    {
        if (!empty($data)) {
            $this->options['data'] = array_merge($this->options['data'] ?? [], $data);
        }

        if (empty($this->options['where'])) {
            $this->parseUpdateData($this->options['data']);
        }

        if (empty($this->options['where'])) {
            // 如果没有任何更新条件则不执行
            throw new DbException('miss update condition');
        }

        $this->parseOptions();
        $sql = $this->builder->update($this);
        $bindParams = $this->getBind();
        return $this->connection->createCommand($sql)->update($bindParams);
    }

    /**
     * 删除记录
     * @access public
     * @param mixed $data 表达式 true 表示强制删除
     * @return int
     * @throws DbException
     */
    public function delete($data = null): int
    {
        if (!is_null($data) && true !== $data) {
            // AR模式分析主键条件
            $this->parsePkWhere($data);
        }

        if (true !== $data && empty($this->options['where'])) {
            // 如果条件为空 不进行删除操作 除非设置 1=1
            throw new DbException('delete without condition');
        }

        if (!empty($this->options['soft_delete'])) {
            // 软删除
            list($field, $condition) = $this->options['soft_delete'];
            if ($condition) {
                unset($this->options['soft_delete']);
                $this->options['data'] = [$field => $condition];

                $this->parseOptions();
                $sql = $this->builder->update($this);
                $bindParams = $this->getBind();
                return $this->connection->createCommand($sql)->update($bindParams);
            }
        }

        $this->options['data'] = $data;
        $this->parseOptions();
        $sql = $this->builder->delete($this);
        $bindParams = $this->getBind();
        return $this->connection->createCommand($sql)->delete($bindParams);
    }

    /**
     * 查找记录
     * @access public
     * @param mixed $data 数据
     * @return Collection|array|static[]
     * @throws DbException
     */
    public function select($data = null): Collection
    {
        if (!is_null($data)) {
            // 主键条件分析
            $this->parsePkWhere($data);
        }

        $this->parseOptions();
        $sql = $this->builder->select($this);
        $bindParams = $this->getBind();

        $resultSet = $this->connection->query($sql, $bindParams);

        // 返回结果处理
        if (!empty($this->options['fail']) && count($resultSet) == 0) {
            $this->throwNotFound();
        }

        return new Collection($resultSet);
    }

    /**
     * 查找单条记录
     * @access public
     * @param mixed $data 查询数据
     * @return array|Model|null|static|mixed
     */
    public function find($data = null)
    {
        if (!is_null($data)) {
            // AR模式分析主键条件
            $this->parsePkWhere($data);
        }

        if (empty($this->options['where']) && empty($this->options['order'])) {
            $result = [];
        } else {
            $this->parseOptions();
            $sql = $this->builder->select($this);
            $bindParams = $this->getBind();
            $resultSet = $this->connection->query($sql, $bindParams);
            $result = $resultSet[0] ?? [];
        }

        return $result;
    }

    /**
     * 原生查询
     *
     * @param string $sql
     * @param array $bindParams
     * @return array
     */
    public function query(string $sql, array $bindParams = []): array
    {
        $result = $this->connection->query($sql, $bindParams, \Pdo::FETCH_ASSOC);
        return $result;
    }

    /**
     * 原生插入或者更新
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function execute(string $sql, array $bindParams = []): int
    {
        return $this->connection->execute($sql, $bindParams);
    }

    /**
     * 分析表达式（可用于查询或者写入操作）
     * @access public
     * @return array
     */
    public function parseOptions(): array
    {
        $options = $this->getOptions();

        // 获取数据表
        if (empty($options['table'])) {
            $options['table'] = $this->getTable();
        }

        if (!isset($options['where'])) {
            $options['where'] = [];
        } elseif (isset($options['view'])) {
            // 视图查询条件处理
            $this->parseView($options);
        }

        foreach (['data', 'order', 'join', 'union', 'filter', 'json', 'with_attr', 'with_relation_attr'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = [];
            }
        }

        if (!isset($options['strict'])) {
            $options['strict'] = $this->connection->getConfig('fields_strict');
        }

        foreach (['master', 'lock', 'fetch_sql', 'array', 'distinct', 'procedure', 'with_cache'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = false;
            }
        }

        foreach (['group', 'having', 'limit', 'force', 'comment', 'partition', 'duplicate', 'extra'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = '';
            }
        }

        if (isset($options['page'])) {
            // 根据页数计算limit
            [$page, $listRows] = $options['page'];
            $page              = $page > 0 ? $page : 1;
            $listRows          = $listRows ?: (is_numeric($options['limit']) ? $options['limit'] : 20);
            $offset            = $listRows * ($page - 1);
            $options['limit']  = $offset . ',' . $listRows;
        }

        $this->options = $options;

        return $options;
    }

    /**
     * 分析数据是否存在更新条件
     * @access public
     * @param array $data 数据
     * @return bool
     * @throws DbException
     */
    public function parseUpdateData(&$data): bool
    {
        $pk       = $this->getPk();
        $isUpdate = false;
        // 如果存在主键数据 则自动作为更新条件
        if (is_string($pk) && isset($data[$pk])) {
            $this->where($pk, '=', $data[$pk]);
            $this->options['key'] = $data[$pk];
            unset($data[$pk]);
            $isUpdate = true;
        } elseif (is_array($pk)) {
            foreach ($pk as $field) {
                if (isset($data[$field])) {
                    $this->where($field, '=', $data[$field]);
                    $isUpdate = true;
                } else {
                    // 如果缺少复合主键数据则不执行
                    throw new DbException('miss complex primary data');
                }
                unset($data[$field]);
            }
        }

        return $isUpdate;
    }

    /**
     * 把主键值转换为查询条件 支持复合主键
     * @access public
     * @param array|string $data 主键数据
     * @return void
     * @throws DbException
     */
    public function parsePkWhere($data): void
    {
        $pk = $this->getPk();

        if (is_string($pk)) {
            // 获取数据表
            if (empty($this->options['table'])) {
                $this->options['table'] = $this->getTable();
            }

            $table = is_array($this->options['table']) ? key($this->options['table']) : $this->options['table'];

            if (!empty($this->options['alias'][$table])) {
                $alias = $this->options['alias'][$table];
            }

            $key = isset($alias) ? $alias . '.' . $pk : $pk;
            // 根据主键查询
            if (is_array($data)) {
                $this->where($key, 'in', $data);
            } else {
                $this->where($key, '=', $data);
                $this->options['key'] = $data;
            }
        }
    }

    /**
     * 获取模型的更新条件
     * @access protected
     * @param array $options 查询参数
     */
    protected function getModelUpdateCondition(array $options)
    {
        return $options['where']['AND'] ?? null;
    }
}
