<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class CpuIntensiveProcesses extends SplBean
{
    protected $pid;
    protected $user;
    protected $usage;
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
     * @param mixed $pid
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
    public function getUsage()
    {
        return $this->usage;
    }

    /**
     * @param mixed $usage
     */
    public function setUsage($usage): void
    {
        $this->usage = $usage;
    }

    /**
     * @return mixed
     */
    public function getRss()
    {
        return $this->rss;
    }

    /**
     * @param mixed $rss
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
     * @param mixed $vsz
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