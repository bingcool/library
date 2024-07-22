<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class DockerProcesses extends SplBean
{
    protected $cname;
    protected $pid;
    protected $user;
    protected $cpuPercent;
    protected $memPercent;
    protected $cmd;

    /**
     * @return mixed
     */
    public function getCname()
    {
        return $this->cname;
    }

    /**
     * @param $cname
     */
    public function setCname($cname): void
    {
        $this->cname = $cname;
    }

    /**
     * @return mixed
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param $pid
     */
    public function setPid($pid): void
    {
        $this->pid = $pid;
    }

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param $user
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function getCpuPercent()
    {
        return $this->cpuPercent;
    }

    /**
     * @param $cpuPercent
     */
    public function setCpuPercent($cpuPercent): void
    {
        $this->cpuPercent = $cpuPercent;
    }

    /**
     * @return mixed
     */
    public function getMemPercent()
    {
        return $this->memPercent;
    }

    /**
     * @param $memPercent
     */
    public function setMemPercent($memPercent): void
    {
        $this->memPercent = $memPercent;
    }

    /**
     * @return mixed
     */
    public function getCmd()
    {
        return $this->cmd;
    }

    /**
     * @param $cmd
     */
    public function setCmd($cmd): void
    {
        $this->cmd = $cmd;
    }
}