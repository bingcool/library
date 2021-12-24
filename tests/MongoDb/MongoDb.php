<?php

namespace Common\Library\Tests\ArrayUtil;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;
use Common\Library\ArrayHelper\ArrayUtil;

class MongoDb extends TestCase
{
    public function testObjectId()
    {
        date_default_timezone_set('PRC');
        $bulk = new \MongoDB\Driver\BulkWrite;
        $document = ['_id' => new \MongoDB\BSON\ObjectID, 'name' => '菜鸟教程'];

        $_id = $bulk->insert($document);

        $timeOx = substr($_id, 0, 8);
        var_dump(date('Y-m-d H:i:s', hexdec($timeOx)));

        $objectId = new \MongoDB\BSON\ObjectID($_id);

        $result = $objectId->getTimestamp();

        var_dump(date('Y-m-d H:i:s', $result));

    }
}