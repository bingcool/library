<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class RamIntensiveProcesses extends SplBean
{

    protected $pid;
    protected $user;
    protected $memPercent;
    protected $rss;
    protected $vsz;
    protected $cmd;

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
     * @param mixed $user
     */
    public function setUser($user): void
    {
        $this->user = $user;
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
    public function getRss()
    {
        return $this->rss;
    }

    /**
     * @param $rss
     */
    public function setRss($rss): void
    {
        $this->rss = $rss;
    }

    /**
     * @return mixed
     */
    public function getVsz()
    {
        return $this->vsz;
    }

    /**
     * @param $vsz
     */
    public function setVsz($vsz): void
    {
        $this->vsz = $vsz;
    }

    /**
     * @return mixed
     */
    public function getCmd()
    {
        return $this->cmd;
    }

    /**
     * @param mixed $cmd
     */
    public function setCmd($cmd): void
    {
        $this->cmd = $cmd;
    }
}
