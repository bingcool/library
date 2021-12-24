<?php

namespace Common\Library\Tests\Protobuf;

use PHPUnit\Framework\TestCase;
use Common\Library\Protobuf\Serializer;

class PBTest extends TestCase
{

    public $serializer;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->serializer = new \Common\Library\Protobuf\Serializer();
    }

    public function testSerializer()
    {
        $req = new PkgGetBookListReq();
        //$req->setUserId('45678');
        $req->setBookId(123456789);

        var_dump(Serializer::serializeToArray($req));

    }

    public function testtestSerializer1(?int $cid = 2)
    {
        $listData = new GetBookListData();
        $listData->setUserId(12345);
        $listData->setAge(30);
        $listData->setName('bingcool');
        //$listData->setSex(1);
        $listData->setPhone('06681234');

        $addr = new Addr();
        $addr->setLat(111.00);
        $addr->setLon(100.1);

        //$listData->setAddr([$addr]);

        $listData->setMapAddr([
            'lonlat' => $addr,
            'lonlat1' => $addr,
            'lonlat2' => $addr
        ]);

        $rsp = new PkgGetBookListRsp();
        $rsp->setMsg('中国人民');
        $rsp->setRet(0);
        $rsp->setData($listData);

        $arr = Serializer::serializeToArray($rsp);

        //var_dump($arr);

        $rsp1 = new PkgGetBookListRsp();
        Serializer::mergeFromArray($rsp1, $arr);

        //$rsp1 = Serializer::decodeMessage($rsp1, $arr);
        var_dump($cid);
        var_dump($rsp1->getData()->getSex());
        foreach ($rsp1->getData()->getMapAddr() as $key => $addr) {
            var_dump($key, $addr->getLat());
        }
    }
}