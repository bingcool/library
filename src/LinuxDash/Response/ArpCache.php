<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class ArpCache extends SplBean
{
    protected $addr;
    protected $hwType;
    protected $hwAddr;
    protected $mask;

    /**
     * @return mixed
     */
    public function getAddr()
    {
        return $this->addr;
    }

    /**
     * @param mixed $addr
     */
    public function setAddr($addr): void
    {
        $this->addr = $addr;
    }

    /**
     * @return mixed
     */
    public function getHwType()
    {
        return $this->hwType;
    }

    /**
     * @param $hwType
     */
    public function setHwType($hwType): void
    {
        $this->hwType = $hwType;
    }

    /**
     * @return mixed
     */
    public function getHwAddr()
    {
        return $this->hwAddr;
    }

    /**
     * @param $hwAddr
     */
    public function setHwAddr($hwAddr): void
    {
        $this->hwAddr = $hwAddr;
    }

    /**
     * @return mixed
     */
    public function getMask()
    {
        return $this->mask;
    }

    /**
     * @param $mask
     */
    public function setMask($mask): void
    {
        $this->mask = $mask;
    }


}