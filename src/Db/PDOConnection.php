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

use PDO;
use PDOStatement;
use Common\Library\Exception\DbException;
use Swoolefy\Core\Log\LogManager;

/**
 * Class PDOConnection
 * @package Common\Library\Db
 */
abstract class PDOConnection implements ConnectionInterface
{

    const PARAM_FLOAT = 21;

    /**
     * 数据库连接参数配置
     * @var array
     */
    protected $config = [
        // 类型
        'type' => 'mysql',
        // 服务器地址
        'hostname' => '',
        // 数据库名
        'database' => '',
        // 用户名
        'username' => '',
        // 密码
        'password' => '',
        // 端口
        'hostport' => '',
        // 连接dsn
        'dsn' => '',
        // 数据库连接参数
        'params' => [],
        // 数据库编码默认采用utf8
        'charset' => 'utf8mb4',
        // 数据库表前缀
        'prefix' => '',
        // fetchType
        'fetch_type' => PDO::FETCH_ASSOC,
        // 是否需要断线重连
        'break_reconnect' => true,
        // 是否支持事务嵌套
        'support_savepoint' => false,
        // sql执行日志条目设置,不能设置太大,适合调试使用,设置为0，则不使用
        'spend_log_limit' => 30,
        // 是否开启dubug
        'debug' => 1,
    ];

    /**
     * @var \PDO
     */
    protected $PDOInstance;

    /**
     * PDO操作实例
     * @var \PDOStatement
     */
    protected $PDOStatement;

    /**
     * 当前SQL指令
     * @var string
     */
    protected $queryStr = '';

    /**
     * @var int
     */
    private $transTimes;

    /**
     * @var int
     */
    private $reConnectTimes;

    /**
     * @var array
     */
    private $bind;

    /**
     * @var int
     */
    private $fetchType = PDO::FETCH_ASSOC;

    /**
     * @var int
     */
    private $attrCase = PDO::CASE_LOWER;

    /**
     * @var int
     */
    private $numRows;

    /**
     * 数据表字段信息
     * @var array
     */
    protected $_tableFields = [];

    /**
     * @var array
     */
    protected $info = [];

    /**
     * @var array
     */
    protected $lastLogs = [];

    /**
     * @var array
     */
    protected $excelSqlArr = [];

    /**
     * @var int
     */
    public $debug = 1;

    /**
     * @var null|int
     */
    public $dynamicDebug = null;

    /**
     * @var array
     */
    protected $afterCommitCallbacks = [];

    /**
     * @var array
     */
    protected $afterRollBackCallbacks = [];

    /**
     * @var array
     */
    protected static $slowSqlNoticeCallback = [];

    /**
     * PDO连接参数
     * @var array
     */
    protected $params = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_AUTOCOMMIT => 1 //必须设置为1，否则在事务commit后,后面insert将无法进行
    ];

    /**
     * 参数绑定类型映射
     * @var array
     */
    protected $bindType = [
        'string' => PDO::PARAM_STR,
        'str' => PDO::PARAM_STR,
        'integer' => PDO::PARAM_INT,
        'int' => PDO::PARAM_INT,
        'boolean' => PDO::PARAM_BOOL,
        'bool' => PDO::PARAM_BOOL,
        'float' => self::PARAM_FLOAT,
        'datetime' => PDO::PARAM_STR,
        'timestamp' => PDO::PARAM_STR,
    ];

    /**
     * 服务器断线标识字符
     * @var array
     */
    protected $breakMatchStr = [
        'server has gone away',
        'no connection to the server',
        'Lost connection',
        'is dead or not enabled',
        'Error while sending',
        'decryption failed or bad record mac',
        'server closed the connection unexpectedly',
        'SSL connection has been closed unexpectedly',
        'Error writing data to the connection',
        'Resource deadlock avoided',
        'failed with errno',
        'child connection forced to terminate due to client_idle_limit',
        'query_wait_timeout',
        'reset by peer',
        'Physical connection is not usable',
        'TCP Provider: Error code 0x68',
        'ORA-03114',
        'Packets out of order. Expected',
        'Adaptive Server connection failed',
        'Communication link failure',
        'connection is no longer usable',
        'Server shutdown in progress',
        'Login timeout expired',
        'SQLSTATE[HY000] [2002] Connection refused',
        'running with the --read-only option so it cannot execute this statement',
        'The connection is broken and recovery is not possible. The connection is marked by the client driver as unrecoverable. No attempt was made to restore the connection.',
        'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Try again',
        'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Name or service not known',
        'SQLSTATE[HY000]: General error: 7 SSL SYSCALL error: EOF detected',
        'SQLSTATE[HY000] [2002] Connection timed out',
        'SSL: Connection timed out',
        'SQLSTATE[HY000]: General error: 1105 The last transaction was aborted due to Seamless Scaling. Please retry.',
    ];

    /**
     * @param array $config 数据库配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
        $this->fetchType = $this->config['fetch_type'] ?: PDO::FETCH_ASSOC;
        $this->debug = (int)$this->config['debug'] ?? 1;
        $this->enableDynamicDebug();
    }

    /**
     * @param bool $isDebug
     * @return void
     */
    public function setDebug(bool $isDebug = true)
    {
        $this->debug = (int) $isDebug;
    }

    /**
     * enableDynamicDebug
     *
     * @param bool $isDynamicDebug
     * @return void
     */
    public function enableDynamicDebug()
    {
        if (\Swoole\Coroutine::getCid() >=0 && \Swoolefy\Core\Coroutine\Context::has('db_debug')) {
            $debug = \Swoolefy\Core\Coroutine\Context::get('db_debug');
            $debug = (int) $debug;
            if ($debug) {
                $this->debug = $debug;
                $this->dynamicDebug = $debug;
            }else {
                $this->dynamicDebug = $debug;
            }
        }
    }

    /**
     * 连接数据库
     * @param array $config
     * @param bool $autoConnection
     * @param bool $force
     * @return mixed|PDO
     */
    public function connect(array $config = [], bool $autoConnection = true, bool $force = false)
    {
        // 开启事物，整个事物的PDOInstance必须是要同一个
        if (!$force || !empty($this->transTimes)) {
            if ($this->PDOInstance) {
                return $this->PDOInstance;
            }
        }

        $this->config = array_merge($this->config, $config);
        $this->fetchType = $this->config['fetch_type'] ?: PDO::FETCH_ASSOC;
        if (isset($this->config['params']) && is_array($this->config['params'])) {
            $params = $this->config['params'] + $this->params;
        } else {
            $params = $this->params;
        }

        try {
            if (empty($this->config['dsn'])) {
                $this->config['dsn'] = $this->parseDsn();
            }
            $startTime = $this->debug ? microtime(true) : 0;
            $this->PDOInstance = $this->createPdo($this->config['dsn'], $this->config['username'], $this->config['password'], $params);
            $endTime = $this->debug ? microtime(true) : 0;
            $this->log('Connect start', 'Connect successful, Spend Time=' . ($endTime - $startTime));
            return $this->PDOInstance;
        } catch (\PDOException|\Exception|\Throwable $exception) {
            if ($autoConnection) {
                $this->log('Connect failed, try to connect once again', 'Connect failed, errorMsg=' . $exception->getMessage());
                $force = false;
                // no start transaction
                if(empty($this->transTimes)) {
                    $force = true;
                }
                return $this->connect([], false, $force);
            } else {
                throw $exception;
            }
        }
    }

    /**
     * @param bool $force
     */
    public function initConnect(bool $force = false): void
    {
        $this->connect($this->config, true, $force);
    }

    /**
     * @return PDOConnection
     */
    public function getConnection()
    {
        return $this;
    }

    /**
     * @return Query
     */
    public function newQuery(): Query
    {
        return new Query($this);
    }

    /**
     * 创建PDO实例
     * @param $dsn
     * @param $username
     * @param $password
     * @param $params
     * @return PDO
     */
    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return new PDO($dsn, $username, $password, $params);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @param bool $procedure
     * @return PDOStatement
     * @throws \PDOException
     * @throws \Exception
     * @throws \Throwable
     */
    public function PDOStatementHandle(string $sql, array $bindParams = []): PDOStatement
    {
        $this->initConnect();
        // 记录SQL语句
        $this->queryStr = $sql;
        $this->bind = $bindParams;
        $readSql = "";
        try {
            if ($this->debug) {
                $queryStartTime = microtime(true);
                $readSql = $this->getRealSql($this->queryStr, $this->bind);
                $this->log('Execute sql start', "sql={$readSql},bindParams=" . json_encode($bindParams, JSON_UNESCAPED_UNICODE));
            }
            // 预处理
            $this->PDOStatement = $this->PDOInstance->prepare($sql);
            // 参数绑定
            $this->bindValue($bindParams);

            // 执行查询
            $this->PDOStatement->execute();

            $this->saveRuntimeSql($queryStartTime ?? (microtime(true)) );

            $this->reConnectTimes = 0;
            return $this->PDOStatement;
        } catch (\PDOException $e) {
            if ($this->reConnectTimes < 4 && ($this->isBreak($e) || $e->errorInfo[1] == 2006 || $e->errorInfo[1] == 2013)) {
                ++$this->reConnectTimes;
                return $this->close()->PDOStatementHandle($sql, $bindParams);
            }
            throw $e;
        } catch (\Exception|\Throwable $t) {
            $this->log('Execute sql error', $t->getMessage());
            throw $t;
        } finally {
            if ($this->debug) {
                if (count($this->excelSqlArr) <= 100) {
                    if (empty($readSql)) {
                        $readSql = $this->getRealSql($this->queryStr, $this->bind);
                    }
                    $this->excelSqlArr[] = $readSql;
                }
            }
        }
    }

    /**
     * @return PDOStatement|null
     */
    public function getPDOStatement(): PDOStatement
    {
        return $this->PDOStatement;
    }

    /**
     * 获取PDO对象
     * @access public
     * @return \PDO|false
     */
    public function getPdo(): PDo
    {
        if (!$this->PDOInstance) {
            return false;
        }
        return $this->PDOInstance;
    }

    /**
     * 游标-生成器迭代处理,可用于处理大量数据
     *
     * @param string $sql
     * @param array $bindParams
     * @param $fetchType
     * @return \Generator
     * @throws \Throwable
     */
    public function cursor(string $sql, array $bindParams, $fetchType = '')
    {
        $this->PDOStatementHandle($sql, $bindParams);
        if (empty($fetchType)) {
            $fetchType = $this->fetchType;
        }
        while ($result = $this->PDOStatement->fetch($fetchType)) {
            yield $result;
        }
    }

    /**
     * 参数绑定
     * 支持 [':name'=>'value',':id'=>123] 对应命名占位符
     * 或者 ['value',123] 对应问号占位符
     * @param array $bindParams
     * @return void
     * @throws \Exception
     */
    protected function bindValue(array $bindParams = []): void
    {
        foreach ($bindParams as $key => $val) {
            // 占位符
            $param = is_numeric($key) ? $key + 1 : $key;
            if (is_array($val)) {
                if (PDO::PARAM_INT == $val[1] && '' === $val[0]) {
                    $val[0] = 0;
                } elseif (self::PARAM_FLOAT == $val[1]) {
                    $val[0] = is_string($val[0]) ? (float)$val[0] : $val[0];
                    $val[1] = PDO::PARAM_STR;
                }
                $result = $this->PDOStatement->bindValue($param, $val[0], $val[1]);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }

            if (!$result) {
                throw new DbException("Error occurred  when binding parameters '{$param}',lastSql=" . $this->getRealSql($this->queryStr, $bindParams));
            }
        }
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return array
     */
    public function query(string $sql, array $bindParams = [], $fetchType = ''): array
    {
        $this->PDOStatementHandle($sql, $bindParams);
        return $this->getResult($fetchType) ?? [];
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function execute(string $sql, array $bindParams = []): int
    {
        $this->PDOStatementHandle($sql, $bindParams);
        $this->numRows = $this->PDOStatement->rowCount();
        return $this->numRows;
    }

    /**
     * @param array $bindParams
     * @return int
     */
    public function insert(array $bindParams = []): int
    {
        return $this->execute($this->queryStr, $bindParams);
    }

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function update(array $bindParams = []): int
    {
        return $this->execute($this->queryStr, $bindParams);
    }

    /**
     * @param array $bindParams
     * @return int
     */
    public function delete(array $bindParams = []): int
    {
        return $this->execute($this->queryStr, $bindParams);
    }

    /**
     * @param string $sql
     */
    public function createCommand(string $sql)
    {
        $this->queryStr = $sql;
        return $this;
    }

    /**
     * @param array $bindParams
     */
    public function count(array $bindParams = [])
    {
        return $this->queryScalar($bindParams);
    }

    /**
     * @param string $table
     * @param array $fields
     * @param array $dataSet
     * @return integer
     */
    public function batchInsert(string $table, array $fields, array $dataSet)
    {
        $fieldStr = implode(',', $fields);
        $sql = "INSERT INTO {$table} ($fieldStr) VALUES ";
        $sqlArr = [];
        $tableFieldInfo = $this->getTableFieldsInfo($table);
        foreach ($dataSet as $row) {
            $row = array_values($row);
            foreach ($row as $i => &$value) {
                if (isset($fields[$i])) {
                    $fieldName = $fields[$i];
                    $type = $tableFieldInfo[$fieldName] ?? 'string';
                    switch ($type) {
                        case 'int':
                        case 'integer':
                        case 'timestamp':
                            $value = (int)$value;
                            break;
                        case 'float':
                        case 'double':
                            $value = (float)$value;
                            break;
                        case 'bool':
                        case 'boolean':
                            $value = (int)$value;
                            break;
                        default:
                            $value = $this->quote($value);
                            break;
                    }
                }
            }
            $sqlArr[] = '(' . implode(',', $row) . ')';
        }

        $sql .= implode(',', $sqlArr);

        return $this->createCommand($sql)->insert();
    }

    /**
     * 批量插入数据(推荐使用)
     * @param string $table
     * @param array $dataSet
     * @return int
     */
    public function multiInsert(string $table, array $dataSet)
    {
        $fields = [];
        $paramsKeys = [];
        $params = [];
        foreach ($dataSet as $index => $data) {
            foreach ($data as $k => $v) {
                $fields[$k] = $k;
                $paramsKeys[$index][] = $paramKey = ":{$k}_{$index}";
                $params[$paramKey] = $v;
            }
            $paramsKeys[$index] = "(" . implode(',', $paramsKeys[$index]) . ")";
        }

        $sql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES " . implode(',', $paramsKeys);

        return $this->createCommand($sql)->insert($params);
    }

    /**
     * 批量插入数据(推荐使用)
     * @param string $table
     * @param array $dataSet
     * @return int
     */
    public function insertAll(string $table, array $dataSet)
    {
        return $this->multiInsert($table, $dataSet);
    }

    /**
     * @param array $bindParams
     * @param int $fetchType
     * @return array|mixed
     */
    public function findOne(array $bindParams = [], int $fetchType = PDO::FETCH_ASSOC)
    {
        return $this->queryOne($bindParams, $fetchType);
    }

    /**
     * @param array $bindParams
     * @param int $fetchType
     * @return array|mixed
     */
    public function queryOne(array $bindParams = [], int $fetchType = PDO::FETCH_ASSOC)
    {
        $this->PDOStatementHandle($this->queryStr, $bindParams);
        $result = $this->PDOStatement->fetch($fetchType);
        $this->PDOStatement->closeCursor();
        return $result;
    }

    /**
     * @param array $bindParams
     * @param bool $one
     * @return array
     */
    public function queryAll(array $bindParams = [], bool $one = false)
    {
        $sql = $one ? $this->queryStr . ' LIMIT 1' : $this->queryStr;
        return $this->query($sql, $bindParams);
    }

    /**
     * @param array $bindParams
     * @return mixed
     */
    public function queryColumn(array $bindParams = [])
    {
        $this->PDOStatementHandle($this->queryStr, $bindParams);
        $result = $this->PDOStatement->fetchAll(PDO::FETCH_COLUMN);
        $this->PDOStatement->closeCursor();
        return $result;
    }

    /**
     * 获取某个标量
     * @param array $bindParams
     */
    public function queryScalar(array $bindParams = [])
    {
        $this->PDOStatementHandle($this->queryStr, $bindParams);
        return $this->PDOStatement->fetchColumn(0);
    }

    /**
     * 获得数据集数组
     * @return array
     */
    protected function getResult($fetchType): array
    {
        if (empty($fetchType)) {
            $fetchType = $this->fetchType;
        }

        $result = $this->PDOStatement->fetchAll($fetchType);

        $this->numRows = count($result);

        return $result;
    }

    /**
     * 解析pdo连接的dsn信息
     * @return string
     */
    abstract protected function parseDsn();

    /**
     * 取得数据表的字段信息
     * @param string $tableName 数据表名称
     * @return array
     */
    abstract public function getFields(string $tableName);

    /**
     * 取得数据库的表信息
     * @param string $dbName 数据库名称
     * @return array
     */
    abstract public function getTables(string $dbName);

    /**
     * 获取数据库的配置参数
     * @param string $name
     * @return array|mixed|string
     */
    public function getConfig(string $name = '')
    {
        if ($name) {
            return $this->config[$name] ?? '';
        }

        return $this->config;
    }

    /**
     * 获取数据表信息
     * @param mixed $tableName 数据表名
     * @param string $fetch 获取信息类型 值包括 fields type bind pk
     * @return mixed
     */
    public function getTableInfo($tableName, string $fetch = '')
    {
        $tableName = $this->parseTableName($tableName);

        if (empty($tableName)) {
            return [];
        }

        list($tableName) = explode(' ', $tableName);

        $info = $this->getSchemaInfo($tableName);
        return $fetch ? $info[$fetch] : $info;
    }

    /**
     * @param $tableName
     * @return mixed
     */
    public function parseTableName($tableName)
    {
        if (is_array($tableName)) {
            $tableName = key($tableName) ?: current($tableName);
        }

        if (strpos($tableName, ',') || strpos($tableName, ')')) {
            // 多表不获取字段信息
            return [];
        }

        [$tableName] = explode(' ', $tableName);

        return $tableName;
    }

    /**
     * 获取数据表字段类型
     * @access public
     * @param mixed $tableName 数据表名
     * @param string $field 字段名
     * @return string
     */
    public function getFieldType($tableName, string $field)
    {
        $result = $this->getTableInfo($tableName, 'type');

        if ($field && isset($result[$field])) {
            return $result[$field];
        }

        return $result;
    }

    /**
     * @param string $string
     * @param int $parameterType
     * @return string
     */
    public function quote(string $string, $parameterType = PDO::PARAM_STR): string
    {
        $quoteString = $this->PDOInstance->quote($string, $parameterType);
        if ($quoteString === false) {
            $quoteString = addcslashes(str_replace("'", "''", $string), "\000\n\r\\\032");
        }

        return $quoteString;
    }

    /**
     * 获取数据表的自增主键
     * @param mixed $tableName 数据表名
     * @return string
     */
    public function getAutoInc($tableName): string
    {
        return $this->getTableInfo($tableName, 'autoinc');
    }

    /**
     * 获取数据表的主键
     * @access public
     * @param mixed $tableName 数据表名
     * @return string|array
     */
    public function getPk($tableName)
    {
        return $this->getTableInfo($tableName, 'pk');
    }

    /**
     * Schema
     *
     * @param string $tableName 数据表名称
     * @param bool $force 强制从数据库获取
     * @return array
     */
    public function getSchemaInfo(string $tableName, bool $force = false): array
    {
        if (!strpos($tableName, '.')) {
            $schema = $this->getConfig('database') . '.' . $tableName;
        } else {
            $schema = $tableName;
        }

        if (!isset($this->info[$schema]) || $force || isset($this->objExpireTime)) {
            $info = $this->getTableFieldsInfo($tableName);
            $pk = $info['_pk'] ?? null;
            $autoinc = $info['_autoinc'] ?? null;
            unset($info['_pk'], $info['_autoinc']);

            $bind = [];
            foreach ($info as $name => $val) {
                $bind[$name] = $this->getFieldBindType($val);
            }

            $this->info[$schema] = [
                'fields' => array_keys($info),
                'type' => $info,
                'bind' => $bind,
                'pk' => $pk,
                'autoinc' => $autoinc,
            ];
        }

        return $this->info[$schema];
    }

    /**
     * 获取数据表字段信息
     * @access public
     * @param mixed $tableName 数据表名
     * @return array
     */
    public function getTableFields($tableName): array
    {
        return $this->getTableInfo($tableName, 'fields');
    }

    /**
     * 获取数据表的字段信息
     * @param string $tableName 数据表名
     * @return array
     */
    public function getTableFieldsInfo(string $tableName): array
    {
        $fields = $this->getFields($tableName);
        $info = [];

        foreach ($fields as $key => $val) {
            // 记录字段类型
            $info[$key] = $this->parseFieldType($val['type']);

            if (!empty($val['primary'])) {
                $pk[] = $key;
            }

            if (!empty($val['autoinc'])) {
                $autoinc = $key;
            }
        }

        if (isset($pk)) {
            // 设置主键
            $pk = count($pk) > 1 ? $pk : $pk[0];
            $info['_pk'] = $pk;
        }

        if (isset($autoinc)) {
            $info['_autoinc'] = $autoinc;
        }

        return $info;
    }

    /**
     * 获取字段类型
     * @param string $type 字段类型
     * @return string
     */
    protected function parseFieldType(string $type): string
    {
        if (0 === strpos($type, 'set') || 0 === strpos($type, 'enum')) {
            $result = 'string';
        } elseif (preg_match('/(double|float|decimal|real|numeric)/is', $type)) {
            $result = 'float';
        } elseif (preg_match('/(int|serial|bit)/is', $type)) {
            $result = 'int';
        } elseif (preg_match('/bool/is', $type)) {
            $result = 'bool';
        } elseif (0 === strpos($type, 'timestamp')) {
            $result = 'timestamp';
        } elseif (0 === strpos($type, 'datetime')) {
            $result = 'datetime';
        } elseif (0 === strpos($type, 'date')) {
            $result = 'date';
        } else {
            $result = 'string';
        }

        return $result;
    }

    /**
     * 获取字段绑定类型
     * @param string $type 字段类型
     * @return integer
     */
    public function getFieldBindType(string $type): int
    {
        if (in_array($type, ['integer', 'string', 'float', 'boolean', 'bool', 'int', 'str'])) {
            $bind = $this->bindType[$type];
        } elseif (0 === strpos($type, 'set') || 0 === strpos($type, 'enum')) {
            $bind = PDO::PARAM_STR;
        } elseif (preg_match('/(double|float|decimal|real|numeric)/is', $type)) {
            $bind = self::PARAM_FLOAT;
        } elseif (preg_match('/(int|serial|bit)/is', $type)) {
            $bind = PDO::PARAM_INT;
        } elseif (preg_match('/bool/is', $type)) {
            $bind = PDO::PARAM_BOOL;
        } else {
            $bind = PDO::PARAM_STR;
        }

        return $bind;
    }


    /**
     * 对返数据表字段信息进行大小写转换出来
     * @param array $info 字段信息
     * @return array
     */
    public function fieldCase(array $info): array
    {
        // 字段大小写转换
        switch ($this->attrCase) {
            case PDO::CASE_LOWER:
                $info = array_change_key_case($info);
                break;
            case PDO::CASE_UPPER:
                $info = array_change_key_case($info, CASE_UPPER);
                break;
            case PDO::CASE_NATURAL:
            default:
                // 不做转换
        }

        return $info;
    }

    /**
     * 是否开启事物
     *
     * @return bool
     */
    public function isEnableTransaction(): bool
    {
        if($this->transTimes > 0) {
            return true;
        }

        return false;
    }


    /**
     * 启动事务
     * @return void
     * @throws \Throwable
     */
    public function beginTransaction()
    {
        $this->initConnect(true);
        ++$this->transTimes;

        try {
            if ($this->transTimes == 1) {
                $this->PDOInstance->beginTransaction();
            } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
                $this->PDOInstance->exec(
                    $this->parseSavepoint('trans' . $this->transTimes)
                );
            }
            $this->reConnectTimes = 0;
            $this->log('Start transaction', 'reConnectTimes=' . $this->reConnectTimes);
        } catch (\PDOException|\Exception|\Throwable $exception) {
            if ($this->reConnectTimes < 4 && $this->isBreak($exception)) {
                --$this->transTimes;
                ++$this->reConnectTimes;
                $this->close()->beginTransaction();
                $this->log('Start transaction failed, try to start again', 'reConnectTimes=' . $this->reConnectTimes);
            }
            throw $exception;
        }
    }

    /**
     * 是否支持事务嵌套
     * @return bool
     */
    protected function supportSavepoint(): bool
    {
        return $this->config['support_savepoint'] ?? false;
    }

    /**
     * 生成定义保存点的SQL
     * @param string $name 标识
     * @return string
     */
    protected function parseSavepoint(string $name): string
    {
        return 'SAVEPOINT ' . $name;
    }

    /**
     * 生成回滚到保存点的SQL
     * @return string
     */
    protected function parseSavepointRollBack(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . $name;
    }

    /**
     * 用于非自动提交状态下面的查询提交
     * @return void
     * @throws \Throwable
     */
    public function commit()
    {
        $this->initConnect();
        $this->log('Transaction commit start', 'transaction commit start');
        // 不管多少层内嵌事务，最外层一次commit时候才真正一次性提交commit
        if ($this->transTimes == 1) {
            $this->PDOInstance->commit();
            if(!empty($this->afterCommitCallbacks)) {
                foreach($this->afterCommitCallbacks as $k=>$afterCommitCallback) {
                    try {
                        call_user_func($afterCommitCallback);
                    }catch (\Throwable $throwable)
                    {
                    } finally {
                        unset($this->afterCommitCallbacks[$k]);
                    }
                }
            }
        }
        --$this->transTimes;

        $this->log('Transaction commit finish', 'transaction commit ok');
    }


    /**
     *
     * @param callable $callback
     * @return mixed
     */
    public function afterCommitCallback(?callable $callback = null) {
        if(is_callable($callback)) {
            $this->afterCommitCallbacks[] = $callback;
        }
    }

    /**
     * 事务回滚
     * @return void
     * @throws \Throwable
     */
    public function rollback()
    {
        $this->initConnect();
        $this->log('Transaction commit', 'transaction commit failed');
        $this->log('Transaction rollback start', 'transaction rollback start');

        $callback = function () {
            if(!empty($this->afterRollBackCallbacks)) {
                foreach($this->afterRollBackCallbacks as $k=>$afterRollBackCallback) {
                    try {
                        call_user_func($afterRollBackCallback);
                    }catch (\Throwable $throwable)
                    {
                    } finally {
                        unset($this->afterRollBackCallbacks[$k]);
                    }
                }
            }
        };

        if ($this->transTimes == 1) {
            $this->PDOInstance->rollBack();
            $callback();
        } elseif ($this->transTimes > 1 && $this->supportSavepoint()) {
            $this->PDOInstance->exec(
                $this->parseSavepointRollBack('trans' . $this->transTimes)
            );
            $callback();
        }

        $this->transTimes = max(0, $this->transTimes - 1);
        $this->log('Transaction rollback finish', 'transaction rollback ok');

    }

    /**
     *
     * @param callable $callback
     * @return mixed
     */
    public function afterRollbackCallback(?callable $callback = null) {
        if(is_callable($callback)) {
            $this->afterRollBackCallbacks[] = $callback;
        }
    }

    /**
     * 执行数据库Xa事务
     * @access public
     * @param  callable $callback 数据操作方法回调
     * @param  array    $dbs      多个查询对象或者连接对象
     * @return mixed
     * @throws \PDOException
     * @throws \Exception
     * @throws \Throwable
     */
    public function transactionXa(callable $callback, array $dbs = [])
    {
        $xid = uniqid('xa');

        if (empty($dbs)) {
            $dbs[] = $this;
        }

        foreach ($dbs as $key => $connection) {

            $connection->startTransXa($connection->getUniqueXid('_' . $xid) );
        }

        try {
            $result = null;
            if (is_callable($callback)) {
                $result = $callback($this);
            }

            foreach ($dbs as $connection) {
                $connection->prepareXa($connection->getUniqueXid('_' . $xid));
            }

            foreach ($dbs as $connection) {
                $connection->commitXa($connection->getUniqueXid('_' . $xid) );
            }

            return $result;
        } catch (\Exception | \Throwable $e) {
            foreach ($dbs as $connection) {
                $connection->rollbackXa($connection->getUniqueXid('_' . $xid) );
            }
            throw $e;
        }
    }

    /**
     * 启动XA事务
     * @param string $xid XA事务id
     * @return void
     */
    public function startTransXa(string $xid)
    {
    }

    /**
     * 预编译XA事务
     * @param string $xid XA事务id
     * @return void
     */
    public function prepareXa(string $xid)
    {
    }

    /**
     * 提交XA事务
     * @param string $xid XA事务id
     * @return void
     */
    public function commitXa(string $xid)
    {
    }

    /**
     * 回滚XA事务
     * @param string $xid XA事务id
     * @return void
     */
    public function rollbackXa(string $xid)
    {
    }

    /**
     * 关闭数据库（或者重新连接）
     * @return $this
     */
    public function close()
    {
        $this->lastLogs = [];
        $this->free();
        return $this;
    }

    /**
     * 释放查询结果
     * @access public
     */
    public function free(): void
    {
        $this->PDOInstance = null;
        $this->PDOStatement = null;
    }

    /**
     * 是否断线
     * @param \PDOException|\Exception $e 异常对象
     * @return bool
     */
    protected function isBreak($e): bool
    {
        if (!$this->config['break_reconnect']) {
            return false;
        }

        $error = $e->getMessage();

        foreach ($this->breakMatchStr as $msg) {
            if (false !== stripos($error, $msg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 根据参数绑定组装最终的SQL语句 便于调试
     * @param string $sql 带参数绑定的sql语句
     * @param array $bind 参数绑定列表
     * @return string
     */
    public function getRealSql(string $sql, array $bind = []): string
    {
        foreach ($bind as $key => $val) {
            $value = strval(is_array($val) ? $val[0] : $val);
            $type  = is_array($val) ? $val[1] : PDO::PARAM_STR;

            if (self::PARAM_FLOAT == $type || PDO::PARAM_STR == $type) {
                $value = '\'' . addslashes($value) . '\'';
            } elseif (PDO::PARAM_INT == $type && '' === $value) {
                $value = '0';
            }

            // 判断占位符
            if (is_numeric($key)) {
                $sql = substr_replace($sql, $value, strpos($sql, '?'), 1);
            }else {
                if (strpos($key,':') === 0) {
                    $sql = substr_replace($sql, $value, strpos($sql, $key), strlen($key));
                }else {
                    $sql = substr_replace($sql, $value, strpos($sql, ':' . $key), strlen(':' . $key));
                }
            }
        }

        return rtrim($sql);
    }

    /**
     * 获取最近插入的ID
     * @param string $sequence 自增序列名
     * @param int|string $pkValue 自定义的主键唯一值
     * @return int|string
     */
    public function getLastInsID($sequence = null, $pkValue = 0)
    {
        try {
            $insertId = $this->PDOInstance->lastInsertId($sequence);
        } catch (\Throwable $exception) {
            $insertId = 0;
        }

        if ($insertId > 0) {
            if (is_numeric($insertId)) {
                $insertId = (int)$insertId;
            }
        }else {
            $insertId = $pkValue;
        }

        return $insertId ?? null;
    }

    /**
     * 获取最近一次查询的sql语句
     * @return string
     */
    public function getLastSql(): string
    {
        return $this->getRealSql($this->queryStr, $this->bind);
    }

    /**
     * 获取最近的错误信息
     * @return string
     */
    public function getLastError(): string
    {
        if ($this->PDOStatement) {
            $error = $this->PDOStatement->errorInfo();
            $error = $error[1] . ':' . $error[2];
        } else {
            $error = '';
        }

        if ('' != $this->queryStr) {
            $error .= "\n [ SQL语句 ] : " . $this->getLastsql();
        }

        return $error;
    }

    /**
     * runtime sql
     *
     * @param $queryStartTime
     */
    protected function saveRuntimeSql($queryStartTime)
    {
        $realSql = '';
        $queryEndTime = microtime(true);
        $runTime = number_format(($queryEndTime - $queryStartTime),6);

        if ($this->debug) {
            $this->log('Execute sql end', 'Execute successful, Execute time=' . $runTime);
            // sql log
            $realSql = $this->getRealSql($this->queryStr, $this->bind);
            $dateTime = date('Y-m-d H:i:s');
            if($this->isCoroutine()) {
                $cid = \Swoole\Coroutine::getCid();
                $traceId = '';
                if (\Swoolefy\Core\Coroutine\Context::has('trace-id')) {
                    $traceId = \Swoolefy\Core\Coroutine\Context::get('trace-id');
                }
                $sqlFlag = "sql-cid-{$cid}";
                $logger = LogManager::getInstance()->getLogger(LogManager::SQL_LOG);
                if ($logger) {
                    $logFilePath = $logger->getLogFilePath();
                    if (!file_exists($logFilePath)) {
                        fopen($logFilePath, 'w');
                    }
                    $sqlLog = "【{$dateTime}】【Runtime:{$runTime}】【Trace-Id: {$traceId}】【{$sqlFlag}】: ".$realSql;
                    $logger->info($sqlLog);
                }
            }
        }

        if (isset(static::$slowSqlNoticeCallback['query_time']) && $runTime > static::$slowSqlNoticeCallback['query_time'] ) {
            $this->callSlowSqlFn($runTime, !empty($realSql) ?  $realSql : $this->getRealSql($this->queryStr, $this->bind));
        }
    }

    /**
     * @param float $queryTime
     * @param \Closure $fn
     * @return void
     */
    public static function registerSlowSqlFn(float $queryTime, \Closure $fn)
    {
        static::$slowSqlNoticeCallback = [
            'query_time' => $queryTime,
            'fn' => $fn
        ];
    }

    /**
     * @param $realRunTime
     * @param $realSql
     * @return void
     */
    protected function callSlowSqlFn($realRunTime, $realSql)
    {
        $fn = static::$slowSqlNoticeCallback['fn'] ?? '';
        if (empty($fn)) {
            return;
        }
        if (class_exists('swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            goApp(function () use($realRunTime, $realSql) {
                $traceId = '';
                if (\Swoolefy\Core\Coroutine\Context::has('trace-id')) {
                    $traceId = \Swoolefy\Core\Coroutine\Context::get('trace-id');
                }
                try {
                    $fn = static::$slowSqlNoticeCallback['fn'];
                    $fn($realRunTime, $realSql, $traceId);
                }catch (\Throwable $exception) {

                }
            });
        }else {
            try {
                $fn = static::$slowSqlNoticeCallback['fn'];
                $fn($realRunTime, $realSql, '');
            }catch (\Throwable $exception) {

            }
        }
    }

    /**
     * @param string $action
     * @param string $msg
     */
    protected function log(string $action, string $msg = ''): void
    {
        if ($this->debug) {
            $spendLogLimit = $this->config['spend_log_limit'] ?? 0;
            //使用连接池的话，可能会将多次的执行sql流程存在log中，没有释放，此时看到的sql流程就不准确了,或者清空了前面的
            if ($spendLogLimit) {
                if (count($this->lastLogs) > $spendLogLimit) {
                    $this->lastLogs = [];
                }
                $this->lastLogs[] = ['time' => date('Y-m-d, H:i:s'), 'action' => $action, 'msg' => $msg];
            }
        }
    }

    /**
     * @return int|mixed
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * getLog
     * @return array
     */
    public function getLastLogs(): array
    {
        return $this->lastLogs;
    }

    /**
     * 获取执行的sql，最大限制500条，有些循环执行的数量可能会很大，后面就忽略，防止oom，主要调试用
     *
     * @return array
     */
    public function getExcelSqls()
    {
        return $this->excelSqlArr;
    }

    /**
     * 获取返回或者影响的记录数
     * @return integer
     */
    public function getNumRows(): int
    {
        return (int)$this->numRows;
    }

    /**
     * 是否协程环境
     *
     * @return bool
     */
    protected function isCoroutine(): bool
    {
        if(class_exists('Swoolefy\\Core\\Swfy') && \Swoole\Coroutine::getCid() > 0) {
            return true;
        }

        return false;
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->close();
        $this->lastLogs = [];
    }

}