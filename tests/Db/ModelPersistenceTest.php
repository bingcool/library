<?php

namespace Swoolefy\Library\Tests\Db;

use PHPUnit\Framework\TestCase;
use Swoolefy\Library\Db\Interceptor\TenantLineInterceptor;
use Swoolefy\Library\Db\PDOConnection;
use Swoolefy\Library\Db\Sqlite;
use Swoolefy\Library\Tests\Db\Stub\Article;
use Swoolefy\Library\Tests\Db\Stub\FixedTenantHandler;

class ModelPersistenceTest extends TestCase
{
    private Sqlite $db;

    protected function setUp(): void
    {
        PDOConnection::clearGlobalSqlInterceptors();

        $this->db = new Sqlite(['type' => 'sqlite', 'database' => ':memory:']);
        $this->db->connect();
        $this->db->execute(
            'CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                view_count INTEGER DEFAULT 0,
                tenant_id INTEGER
            )'
        );
    }

    protected function tearDown(): void
    {
        PDOConnection::clearGlobalSqlInterceptors();
    }

    public function testInsertUpdateDeleteThroughQueryBuilder(): void
    {
        $article = new Article($this->db);
        $article->title = 'hello';
        $article->view_count = 1;
        $this->assertTrue($article->save());

        $insertSql = $this->db->getLastSql();
        $this->assertStringContainsString('INSERT', strtoupper($insertSql));
        $this->assertStringContainsString('articles', $insertSql);
        $this->assertTrue($article->isExists());

        $article->title = 'world';
        $article->inc('view_count', 2);
        $this->assertTrue($article->save());

        $updateSql = $this->db->getLastSql();
        $this->assertStringContainsString('UPDATE', strtoupper($updateSql));
        $this->assertStringContainsString('view_count', $updateSql);

        $row = $this->db->createCommand('SELECT title, view_count FROM articles WHERE id = :id')
            ->queryOne([':id' => $article->getPkValue()]);
        $this->assertSame('world', $row['title']);
        $this->assertSame(3, (int) $row['view_count']);

        $this->assertTrue($article->delete(true));
        $deleteSql = $this->db->getLastSql();
        $this->assertStringContainsString('DELETE', strtoupper($deleteSql));

        $count = $this->db->createCommand('SELECT COUNT(*) AS cnt FROM articles')
            ->queryOne();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testModelSaveAppliesSqlInterceptor(): void
    {
        $this->db->addSqlInterceptor(new TenantLineInterceptor(new FixedTenantHandler('100')));

        $article = new Article($this->db);
        $article->title = 'tenant row';
        $this->assertTrue($article->save());

        $insertSql = $this->db->getLastSql();
        $this->assertStringContainsString('tenant_id', $insertSql);

        $row = $this->db->createCommand('SELECT tenant_id FROM articles WHERE id = :id')
            ->queryOne([':id' => $article->getPkValue()]);
        $this->assertSame(100, (int) $row['tenant_id']);
    }

    public function testFindOneUsesQueryBuilder(): void
    {
        $this->db->execute("INSERT INTO articles (title, view_count) VALUES ('find-me', 0)");

        $article = new Article($this->db);
        $found = $article->loadOne(['title' => 'find-me']);

        $this->assertNotNull($found);
        $this->assertSame('find-me', $found->title);

        $selectSql = $this->db->getLastSql();
        $this->assertStringContainsString('SELECT', strtoupper($selectSql));
        $this->assertStringContainsString('articles', $selectSql);
    }

    public function testInstanceModelProxiesQueryMethods(): void
    {
        $this->db->execute("INSERT INTO articles (title, view_count) VALUES ('proxy-me', 0)");

        $rows = Article::model($this->db)
            ->where('title', '=', 'proxy-me')
            ->select();

        $this->assertCount(1, $rows);
        $this->assertSame('proxy-me', $rows[0]['title']);

        $selectSql = $this->db->getLastSql();
        $this->assertStringContainsString('SELECT', strtoupper($selectSql));
        $this->assertStringContainsString('articles', $selectSql);
    }
}
