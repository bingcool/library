<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class IoStats extends SplBean
{
    protected $device;
    protected $reads;
    protected $writes;
    protected $inProg;
    protected $time;

    /**
     * @return mixed
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * @param $device
     */
    public function setDevice($device): void
    {
        $this->device = $device;
    }

    /**
     * @return mixed
     */
    public function getReads()
    {
        return $this->reads;
    }

    /**
     * @param $reads
     */
    public function setReads($reads): void
    {
        $this->reads = $reads;
    }

    /**
     * @return mixed
     */
    public function getWrites()
    {
        return $this->writes;
    }

    /**
     * @param $writes
     */
    public function setWrites($writes): void
    {
        $this->writes = $writes;
    }

    /**
     * @return mixed
     */
    public function getInProg()
    {
        return $this->inProg;
    }

    /**
     * @param $inProg
     */
    public function setInProg($inProg): void
    {
        $this->inProg = $inProg;
    }

    /**
     * @return mixed
     */
    public function getTime()
    {
        return $this->time;
    }

    /**
     * @param $time
     */
    public function setTime($time): void
    {
        $this->time = $time;
    }

}
