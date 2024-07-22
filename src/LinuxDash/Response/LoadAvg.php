<?php

namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class LoadAvg extends SplBean
{
    protected $minAvg1;
    protected $minAvg5;
    protected $minAvg15;

    /**
     * @return mixed
     */
    public function getMinAvg1()
    {
        return $this->minAvg1;
    }

    /**
     * @param mixed $minAvg1
     */
    public function setMinAvg1($minAvg1): void
    {
        $this->minAvg1 = $minAvg1;
    }

    /**
     * @return mixed
     */
    public function getMinAvg5()
    {
        return $this->minAvg5;
    }

    /**
     * @param mixed $minAvg5
     */
    public function setMinAvg5($minAvg5): void
    {
        $this->minAvg5 = $minAvg5;
    }

    /**
     * @return mixed
     */
    public function getMinAvg15()
    {
        return $this->minAvg15;
    }

    /**
     * @param mixed $minAvg15
     */
    public function setMinAvg15($minAvg15): void
    {
        $this->minAvg15 = $minAvg15;
    }
}
