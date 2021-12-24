<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: book.proto

namespace Common\Library\Tests\Protobuf;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Generated from protobuf message <code>Protobuf.PkgGetBookListRsp</code>
 */
class PkgGetBookListRsp extends \Google\Protobuf\Internal\Message
{
    /**
     *响应
     *
     * Generated from protobuf field <code>int32 ret = 1;</code>
     */
    protected $ret = 0;
    /**
     * Generated from protobuf field <code>string msg = 2;</code>
     */
    protected $msg = '';
    /**
     * Generated from protobuf field <code>.Protobuf.GetBookListData data = 3;</code>
     */
    protected $data = null;

    /**
     * Constructor.
     *
     * @param array $data {
     *     Optional. Data for populating the Message object.
     *
     * @type int $ret
     *          响应
     * @type string $msg
     * @type \Common\Library\Tests\Protobuf\GetBookListData $data
     * }
     */
    public function __construct($data = NULL)
    {
        \Common\Library\Tests\Protobuf\Book::initOnce();
        parent::__construct($data);
    }

    /**
     *响应
     *
     * Generated from protobuf field <code>int32 ret = 1;</code>
     * @return int
     */
    public function getRet()
    {
        return $this->ret;
    }

    /**
     *响应
     *
     * Generated from protobuf field <code>int32 ret = 1;</code>
     * @param int $var
     * @return $this
     */
    public function setRet($var)
    {
        GPBUtil::checkInt32($var);
        $this->ret = $var;

        return $this;
    }

    /**
     * Generated from protobuf field <code>string msg = 2;</code>
     * @return string
     */
    public function getMsg()
    {
        return $this->msg;
    }

    /**
     * Generated from protobuf field <code>string msg = 2;</code>
     * @param string $var
     * @return $this
     */
    public function setMsg($var)
    {
        GPBUtil::checkString($var, True);
        $this->msg = $var;

        return $this;
    }

    /**
     * Generated from protobuf field <code>.Protobuf.GetBookListData data = 3;</code>
     * @return \Common\Library\Tests\Protobuf\GetBookListData
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Generated from protobuf field <code>.Protobuf.GetBookListData data = 3;</code>
     * @param \Common\Library\Tests\Protobuf\GetBookListData $var
     * @return $this
     */
    public function setData($var)
    {
        GPBUtil::checkMessage($var, \Common\Library\Tests\Protobuf\GetBookListData::class);
        $this->data = $var;

        return $this;
    }

}

