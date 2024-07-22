<?php


namespace Common\Library\LinuxDash;


use Common\Library\LinuxDash\Response\ArpCache;
use Common\Library\LinuxDash\Response\Bandwidth;
use Common\Library\LinuxDash\Response\CpuIntensiveProcesses;
use Common\Library\LinuxDash\Response\CurrentRam;
use Common\Library\LinuxDash\Response\DiskPartition;
use Common\Library\LinuxDash\Response\GeneralInfo;
use Common\Library\LinuxDash\Response\IoStats;
use Common\Library\LinuxDash\Response\IpAddresses;
use Common\Library\LinuxDash\Response\LoadAvg;
use Common\Library\LinuxDash\Response\RamIntensiveProcesses;
use Common\Library\LinuxDash\Response\Swap;
use Common\Library\LinuxDash\Response\UserAccount;
use Swoole\Coroutine;

class LinuxDash
{
    /*
     * 获取ip地址网卡缓冲信息
     *
     * checked
     */
    public static function arpCache()
    {
        $ret = [];
        $json = self::exec('arpCache.sh');
        foreach ($json as $item){
            $ret[] = new ArpCache($item);
        }
        return $ret;
    }

    /*
     * 获取带宽信息
     * checked
     */
    public static function bandWidth():array
    {
        $ret = [];
        $json = self::exec('bandwidth.sh');
        foreach ($json as $item){
            $ret[] = new Bandwidth($item);
        }
        return $ret;
    }

    /*
     * 获取cpu进程占用排行信息
     */
    public static function cpuIntensiveProcesses()
    {
        $json = self::exec('cpuIntensiveProcesses.sh');
        $ret = [];
        foreach ($json as $item){
            $ret[] = new CpuIntensiveProcesses($item);
        }
        return $ret;
    }

    /**
     * 获取磁盘分区信息
     * @return array
     */
    public static function diskPartitions()
    {
        $json = self::exec('diskPartitions.sh');
        $ret = [];
        foreach ($json as $item){
            $ret[] = new DiskPartition($item);
        }
        return $ret;
    }

    /**
     * 获取当前内存使用信息
     *
     * @return CurrentRam
     */
    public static function currentRam()
    {
        $info = self::exec('currentRam.sh');
        return new CurrentRam($info);
    }

    /**
    * 获取cpu信息
    * @return array
    */
    public static function cpuInfo():array
    {
        return self::exec('cpuInfo.sh');
    }

    /**
     * 获取当前系统信息
     *
     * @return GeneralInfo
     */
    public static function generalInfo()
    {
        $info = self::exec('generalInfo.sh');
        return new GeneralInfo($info);
    }

    /**
     * 获取当前磁盘io统计
     *
     * @return array
     */
    public static function ioStats():array
    {
        $list = [];
        $info = self::exec('ioStats.sh');
        foreach ($info as $value){
            $list[] = new IoStats($value);
        }
        return $list;
    }

    /**
     * 获取ip地址
     * @return IpAddresses[]
     */
    public static function ipAddresses()
    {
        $ret = [];
        $list = swoole_get_local_ip();
        foreach ($list as $key => $item){
            $ret[] = new IpAddresses([
                'interface'=>$key,
                'ip'=>$item
            ]);
        }
        return $ret;
    }

    /**
     * CPU负载信息
     *
     * @return LoadAvg
     */
    public static function loadAvg()
    {
        $info = self::exec('loadAvg.sh');
        return new LoadAvg($info);
    }


    /*
     * 获取内存详细信息,
     * @return array
     */
    public static function memoryInfo()
    {
        $list = self::exec('memoryInfo.sh');
        foreach ($list as $key => $item){
            $list[$key] = trim($item);
        }
        return $list;
    }

    /**
     * 获取进程占用内存排行信息
     *
     * @return RamIntensiveProcesses[]
     */
    public static function ramIntensiveProcesses()
    {
        $info = self::exec('ramIntensiveProcesses.sh');
        $list = [];
        foreach ($info as $item){
            $list[] = new RamIntensiveProcesses($item);
        }
        return $list;
    }

    /**
     * 获取swap交换空间信息
     *
     * @return Swap[]
     */
    public static function swap()
    {
        $info = self::exec('swap.sh');
        $ret = [];
        foreach ($info as $item){
            $ret[] = new Swap($item);
        }
        return $ret;
    }

    /**
     * 获取当前用户名信息
     *
     * @return UserAccount[]
     */
    public static function userAccounts()
    {
        $info = self::exec('userAccounts.sh');
        $ret = [];
        foreach ($info as $item){
            $ret[] = new UserAccount($item);
        }
        return $ret;
    }

    /**
     * 执行调用脚本
     *
     * @param $file
     * @return array
     */
    private static function exec($file):array
    {
        try{
            $js = trim(Coroutine::exec(file_get_contents(__DIR__ . "/Shell/{$file}"))['output']);
            $js = json_decode($js,true);
            if(is_array($js)){
                return $js;
            }
        }catch (\Throwable $throwable){

        }
        return [];
    }
}