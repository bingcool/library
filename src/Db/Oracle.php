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

/**
 * Oracle数据库驱动
 */
class Oracle extends PDOConnection
{
    /**
     * 解析pdo连接的dsn信息
     * @access protected
     * @return string
     */
    protected function parseDsn(): string
    {
        $config = $this->config;
        $dsn = 'oci:dbname=';

        if (!empty($config['hostname'])) {
            //  Oracle Instant Client
            $dsn .= '//' . $config['hostname'] . ($config['hostport'] ? ':' . $config['hostport'] : '') . '/';
        }

        $dsn .= $config['database'];

        if (!empty($config['charset'])) {
            $dsn .= ';charset=' . $config['charset'];
        }

        return $dsn;
    }

    /**
     * 取得数据表的字段信息
     * @access public
     * @param string $tableName
     * @return array
     */
    public function getFields(string $tableName): array
    {
        [$tableName] = explode(' ', $tableName);
        $sql         = "select a.column_name,data_type,DECODE (nullable, 'Y', 0, 1) notnull,data_default, DECODE (A .column_name,b.column_name,1,0) pk from all_tab_columns a,(select column_name from all_constraints c, all_cons_columns col where c.constraint_name = col.constraint_name and c.constraint_type = 'P' and c.table_name = '" . $tableName . "' ) b where table_name = '" . $tableName . "' and a.column_name = b.column_name (+)";

        $pdo    = $this->PDOStatementHandle($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];

        if ($result) {
            foreach ($result as $key => $val) {
                $val = array_change_key_case($val);

                $info[$val['column_name']] = [
                    'name'    => $val['column_name'],
                    'type'    => $val['data_type'],
                    'notnull' => $val['notnull'],
                    'default' => $val['data_default'],
                    'primary' => $val['pk'],
                    'autoinc' => $val['pk'],
                ];
            }
        }

        return $this->fieldCase($info);
    }

    /**
     * 取得数据库的表信息（暂时实现取得用户表信息）
     * @access   public
     * @param string $dbName
     * @return array
     */
    public function getTables(string $dbName = ''): array
    {
        $sql    = 'select table_name from all_tables';
        $pdo    = $this->PDOStatementHandle($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];

        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }

        return $info;
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param string|null $sequence 自增序列名
     * @param int|string $pkValue 自定义的主键唯一值
     * @return mixed
     */
    public function getLastInsID(string $sequence = null, $pkValue = 0)
    {
        if(!is_null($sequence)) {
            $pdo    = $this->PDOStatementHandle("select {$sequence}.currval as id from dual");
            $result = $pdo->fetchColumn();
        }

        return $result ?? $pkValue;
    }

    protected function supportSavepoint(): bool
    {
        return true;
    }
}
