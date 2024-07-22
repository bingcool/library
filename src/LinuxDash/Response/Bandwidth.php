<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class Bandwidth extends SplBean
{
    protected $interface;
    protected $rx;
    protected $tx;
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
    public function getRx()
    {
        return $this->rx;
    }

    /**
     * @param mixed $rx
     */
    public function setRx($rx): void
    {
        $this->rx = $rx;
    }

    /**
     * @return mixed
     */
    public function getTx()
    {
        return $this->tx;
    }

    /**
     * @param mixed $tx
     */
    public function setTx($tx): void
    {
        $this->tx = $tx;
    }

}