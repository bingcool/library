<?php

namespace Swoolefy\Library\Tests\Db;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Swoolefy\Library\Db\Builder\Mysql;
use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Db\Sql;
use Swoolefy\Library\Db\Sqlite;

class UnionDerivedTableTest extends TestCase
{
    private Sqlite $db;

    protected function setUp(): void
    {
        $this->db = new Sqlite(['type' => 'sqlite', 'database' => ':memory:']);
        $this->db->connect();
    }

    public function testNormalizeUnionDerivedTableRemovesBranchParentheses(): void
    {
        $builder = new Mysql($this->db);
        $method = new ReflectionMethod(Mysql::class, 'normalizeUnionDerivedTable');
        $method->setAccessible(true);

        $input = '( ( SELECT 1 AS order_id ) UNION ALL ( SELECT 2 AS order_id ) )';
        $output = $method->invoke($builder, $input);

        $this->assertStringNotContainsString('UNION ALL ( SELECT', $output);
        $this->assertStringContainsString('UNION ALL SELECT', $output);
    }

    public function testUnionSubqueryTableGeneratesAliasedFromClause(): void
    {
        $part1 = '( SELECT 1 AS order_id, \'a\' AS remark )';
        $part2 = '( SELECT 2 AS order_id, \'b\' AS remark )';
        $unionSql = '(' . trim($part1, ' ()') . ') UNION ALL (' . trim($part2, ' ()') . ')';

        $query = new Query($this->db);
        $sql = $query->table(Sql::buildSubSql($unionSql) . ' AS uni')
            ->field(['uni.order_id', 'uni.remark'])
            ->order(['uni.order_id' => 'asc'])
            ->fetchSql()
            ->select();

        $this->assertMatchesRegularExpression('/FROM\s+\(\s*SELECT/i', $sql);
        $this->assertStringContainsString('UNION ALL SELECT', $sql);
        $this->assertDoesNotMatchRegularExpression('/UNION ALL\s+\(\s*SELECT/i', $sql);
        $this->assertMatchesRegularExpression('/\)\s+`?uni`?\s/i', $sql);
    }
}
