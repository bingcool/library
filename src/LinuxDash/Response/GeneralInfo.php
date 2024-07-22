<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class GeneralInfo extends SplBean
{
    protected $os;
    protected $hostname;
    protected $uptime;
    protected $serverTime;

    /**
     * @return mixed
     */
    public function getOs()
    {
        return $this->os;
    }

    /**
     * @param $os
     */
    public function setOs($os): void
    {
        $this->os = $os;
    }

    /**
     * @return mixed
     */
    public function getHostname()
    {
        return $this->hostname;
    }

    /**
     * @param $hostname
     */
    public function setHostname($hostname): void
    {
        $this->hostname = $hostname;
    }

    /**
     * @return mixed
     */
    public function getUptime()
    {
        return $this->uptime;
    }

    /**
     * @param $uptime
     */
    public function setUptime($uptime): void
    {
        $this->uptime = $uptime;
    }

    /**
     * @return mixed
     */
    public function getServerTime()
    {
        return $this->serverTime;
    }

    /**
     * @param $serverTime
     */
    public function setServerTime($serverTime): void
    {
        $this->serverTime = $serverTime;
    }
}
