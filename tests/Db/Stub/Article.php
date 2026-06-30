<?php

namespace Swoolefy\Library\Tests\Db\Stub;

use Swoolefy\Library\Db\Model;
use Swoolefy\Library\Db\Sqlite;

class Article extends Model
{
    protected static $table = 'articles';

    protected $pk = 'id';

    public function __construct(private readonly Sqlite $db)
    {
        parent::__construct();
    }

    public function getConnection(): Sqlite
    {
        return $this->db;
    }
}
