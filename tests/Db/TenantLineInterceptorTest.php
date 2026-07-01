<?php

namespace Swoolefy\Library\Tests\Db;

use PHPUnit\Framework\TestCase;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\Db\Concern\TenantScopeContext;
use Swoolefy\Library\Db\Concern\TenantTableMetadata;
use Swoolefy\Library\Db\Interceptor\TenantLineDemoHandler;
use Swoolefy\Library\Db\Interceptor\TenantLineInterceptor;
use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Library\Db\Sqlite;
use Swoolefy\Library\Tests\Db\Stub\Article;
use Swoolefy\Library\Tests\Db\Stub\FixedTenantHandler;

class TenantLineInterceptorTest extends TestCase
{
    private Sqlite $db;

    protected function setUp(): void
    {
        PDOConnection::clearGlobalSqlInterceptors();
        TenantScopeContext::clearHandler();
        TenantTableMetadata::clearCache();

        $this->db = new Sqlite(['type' => 'sqlite', 'database' => ':memory:']);
        $this->db->connect();
        $this->db->execute(
            'CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tenant_id INTEGER
            )'
        );
        $this->db->execute(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tenant_id INTEGER
            )'
        );

        new TenantLineInterceptor(new FixedTenantHandler('10001'));
    }

    protected function tearDown(): void
    {
        PDOConnection::clearGlobalSqlInterceptors();
        TenantScopeContext::clearHandler();
        TenantTableMetadata::clearCache();
    }

    public function testSelectDoesNotRewriteInnerFromWhenOuterFromIsSubquery(): void
    {
        $innerSql = 'SELECT * FROM orders WHERE user_id = 10000 AND tenant_id = :bound LIMIT 1';
        $outerSql = 'SELECT t1.user_id FROM ( ' . $innerSql . ' ) t1 WHERE t1.user_id <> 1';

        $bind = [':bound' => ['10001', 2]];
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));
        $interceptor->beforeExecute($this->db, $outerSql, $bind);

        $this->assertSame(
            'SELECT t1.user_id FROM ( SELECT * FROM orders WHERE user_id = 10000 AND tenant_id = :bound LIMIT 1 ) t1 WHERE t1.user_id <> 1',
            $outerSql
        );
    }

    public function testSelectSkipsWhenTenantConditionAlreadyExists(): void
    {
        $sql = 'SELECT * FROM orders WHERE tenant_id = :existing AND user_id = 10000';
        $bind = [':existing' => ['10001', 2]];

        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));
        $interceptor->beforeExecute($this->db, $sql, $bind);

        $this->assertSame(
            'SELECT * FROM orders WHERE tenant_id = :existing AND user_id = 10000',
            $sql
        );
    }

    public function testSelectSkipsWhenTenantLiteralConditionExists(): void
    {
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));
        $cases = [
            'SELECT * FROM orders WHERE tenant_id = 10001 AND user_id = 10000',
            "SELECT * FROM orders WHERE tenant_id = '10001' AND user_id = 10000",
            'SELECT * FROM orders o WHERE o.tenant_id = 10001 AND user_id = 10000',
            'SELECT * FROM orders WHERE `tenant_id`=10001',
        ];

        foreach ($cases as $original) {
            $sql = $original;
            $bind = [];
            $interceptor->beforeExecute($this->db, $sql, $bind);
            $this->assertSame($original, $sql, 'literal tenant condition should skip rewrite: ' . $original);
        }
    }

    public function testFindTailOffsetIgnoresKeywordsInsideQuotesAndSubqueries(): void
    {
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));

        $literalOrderBy = "SELECT * FROM orders WHERE note = 'ORDER BY fake' ORDER BY id";
        $bind = [];
        $interceptor->beforeExecute($this->db, $literalOrderBy, $bind);
        $this->assertMatchesRegularExpression(
            "/note = 'ORDER BY fake'\\s*\\)\\s*AND\\s+[`\"]?tenant_id[`\"]?\\s*=\\s*:/i",
            $literalOrderBy
        );
        $this->assertStringContainsString('ORDER BY id', $literalOrderBy);
        $this->assertSame(1, substr_count(strtoupper($literalOrderBy), 'ORDER BY'));

        $subqueryLimit = 'SELECT * FROM orders WHERE id IN (SELECT 1 FROM orders LIMIT 1) LIMIT 10';
        $bind = [];
        $interceptor->beforeExecute($this->db, $subqueryLimit, $bind);
        $this->assertMatchesRegularExpression(
            '/\)\s*AND\s+[`"]?tenant_id[`"]?\s*=\s*:/i',
            $subqueryLimit
        );
        $this->assertTrue(
            strrpos($subqueryLimit, 'tenant_id') < strrpos($subqueryLimit, 'LIMIT 10'),
            'tenant condition should be inserted before outer LIMIT'
        );
    }

    public function testModelGetQueryAppliesTenantScopeOnce(): void
    {
        $article = new Article($this->db);
        $sql = $article->getQuery()
            ->where('title', '=', 'demo')
            ->fetchSql()
            ->select();

        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertSame(1, substr_count(strtolower($sql), 'tenant_id'));
    }

    public function testBuildSqlSubqueryDoesNotDuplicateTenantOnOuterRewrite(): void
    {
        $subSql = $this->db->newQuery()
            ->table('orders')
            ->where(['user_id' => 10000])
            ->limit(1)
            ->buildSql();

        $mainSql = $this->db->newQuery()
            ->field(['t1.user_id'])
            ->table([$subSql => 't1'])
            ->fetchSql()
            ->select();

        $this->assertSame(1, substr_count(strtolower($mainSql), 'tenant_id'));
    }

    public function testTableMetadataSharedCacheBetweenScopeAndInterceptor(): void
    {
        $handler = new FixedTenantHandler('10001');
        $interceptor = new TenantLineInterceptor($handler);

        $countingDb = new class(['type' => 'sqlite', 'database' => ':memory:']) extends Sqlite {
            public int $getFieldsCalls = 0;

            public function getFields(string $tableName): array
            {
                $this->getFieldsCalls++;

                return parent::getFields($tableName);
            }
        };
        $countingDb->connect();
        $countingDb->execute(
            'CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tenant_id INTEGER
            )'
        );

        TenantTableMetadata::tableHasTenantColumn($countingDb, 'orders', $handler);

        $sql = 'SELECT * FROM orders WHERE user_id = 1';
        $bind = [];
        $interceptor->beforeExecute($countingDb, $sql, $bind);

        TenantTableMetadata::shouldIgnore($countingDb, 'orders', $handler);

        $this->assertSame(1, $countingDb->getFieldsCalls);
    }

    public function testInsertSelectFillsTenantColumn(): void
    {
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));

        $sql = 'INSERT INTO orders (user_id, amount) SELECT user_id, amount FROM orders WHERE user_id = 1';
        $bind = [];
        $interceptor->beforeExecute($this->db, $sql, $bind);

        $this->assertStringContainsString('(user_id, amount, `tenant_id`)', $sql);
        $this->assertMatchesRegularExpression(
            '/SELECT\s+user_id,\s*amount\s*,\s*:[\w_]+/i',
            $sql
        );
        $this->assertNotEmpty($bind);
    }

    public function testInsertSelectWithoutColumnListAppendsTenantToProjection(): void
    {
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));

        $sql = 'INSERT INTO orders SELECT user_id, amount FROM orders WHERE user_id = 1';
        $bind = [];
        $interceptor->beforeExecute($this->db, $sql, $bind);

        $this->assertMatchesRegularExpression(
            '/INSERT INTO orders SELECT user_id,\s*amount\s*,\s*:[\w_]+/i',
            $sql
        );
        $this->assertDoesNotMatchRegularExpression(
            '/INSERT INTO orders\s*\([^)]*tenant_id/i',
            $sql
        );
    }

    public function testInsertSelectSkipsWhenTenantColumnAlreadyExists(): void
    {
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));

        $sql = 'INSERT INTO orders (user_id, tenant_id) SELECT user_id, tenant_id FROM orders';
        $bind = [];
        $interceptor->beforeExecute($this->db, $sql, $bind);

        $this->assertSame(
            'INSERT INTO orders (user_id, tenant_id) SELECT user_id, tenant_id FROM orders',
            $sql
        );
    }

    public function testInsertSelectPreservesOnDuplicateKeyUpdateTail(): void
    {
        $interceptor = new TenantLineInterceptor(new FixedTenantHandler('10001'));

        $sql = 'INSERT INTO orders (user_id) SELECT user_id FROM orders ON DUPLICATE KEY UPDATE user_id = user_id';
        $bind = [];
        $interceptor->beforeExecute($this->db, $sql, $bind);

        $this->assertStringContainsString('`tenant_id`', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE user_id = user_id', $sql);
        $this->assertMatchesRegularExpression(
            '/SELECT\s+user_id\s*,\s*:[\w_]+\s+FROM orders ON DUPLICATE KEY UPDATE/i',
            $sql
        );
    }

    public function testTenantScopeUsesTableSuffixForMetadataAndWhere(): void
    {
        $countingDb = new class(['type' => 'sqlite', 'database' => ':memory:']) extends Sqlite {
            public ?string $lastGetFieldsTable = null;

            public function getFields(string $tableName): array
            {
                $this->lastGetFieldsTable = $tableName;

                return parent::getFields($tableName);
            }
        };
        $countingDb->connect();
        $countingDb->execute(
            'CREATE TABLE articles_2024 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                tenant_id INTEGER
            )'
        );

        new TenantLineInterceptor(new FixedTenantHandler('10001'));

        $article = new Article($countingDb);
        $article->setSuffix('_2024');
        $sql = $article->getQuery()->fetchSql()->select();

        $this->assertSame('articles_2024', $countingDb->lastGetFieldsTable);
        $this->assertStringContainsString('articles_2024', $sql);
        $this->assertStringContainsString('tenant_id', $sql);
    }

    public function testDemoHandlerCastsIntTenantIdToString(): void
    {
        SwooleContext::set('tenant_id', 10001);

        $handler = new TenantLineDemoHandler();

        $this->assertSame('10001', $handler->getTenantId());
    }
}
