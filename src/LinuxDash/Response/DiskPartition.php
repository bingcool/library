<?php


namespace Common\Library\LinuxDash\Response;


use Common\Library\Spl\SplBean;

class DiskPartition extends SplBean
{
    protected $fileSystem;
    protected $size;
    protected $used;
    protected $avail;
    protected $usedPercentage;
    protected $mounted;

    /**
     * @return mixed
     */
    public function getFileSystem()
    {
        return $this->fileSystem;
    }

    /**
     * @param mixed $fileSystem
     */
    public function setFileSystem($fileSystem): void
    {
        $this->fileSystem = $fileSystem;
    }

    /**
     * @return mixed
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @param mixed $size
     */
    public function setSize($size): void
    {
        $this->size = $size;
    }

    /**
     * @return mixed
     */
    public function getUsed()
    {
        return $this->used;
    }

    /**
     * @param mixed $used
     */
    public function setUsed($used): void
    {
        $this->used = $used;
    }

    /**
     * @return mixed
     */
    public function getAvail()
    {
        return $this->avail;
    }

    /**
     * @param mixed $avail
     */
    public function setAvail($avail): void
    {
        $this->avail = $avail;
    }

    /**
     * @return mixed
     */
    public function getUsedPercentage()
    {
        return $this->usedPercentage;
    }

    /**
     * @param mixed $usedPercentage
     */
    public function setUsedPercentage($usedPercentage): void
    {
        $this->usedPercentage = $usedPercentage;
    }

    /**
     * @return mixed
     */
    public function getMounted()
    {
        return $this->mounted;
    }

    /**
     * @param mixed $mounted
     */
    public function setMounted($mounted): void
    {
        $this->mounted = $mounted;
    }
}