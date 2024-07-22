<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class IpAddresses extends SplBean
{
    protected $interface;
    protected $ip;

    /**
     * @return mixed
     */
    public function getInterface()
    {
        return $this->interface;
    }

    /**
     * @param mixed $interface
     */
    public function setInterface($interface): void
    {
        $this->interface = $interface;
    }

    /**
     * @return mixed
     */
    public function getIp()
    {
        return $this->ip;
    }

    /**
     * @param mixed $ip
     */
    public function setIp($ip): void
    {
        $this->ip = $ip;
    }
}
