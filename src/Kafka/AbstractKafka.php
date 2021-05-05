<?php
/**
+----------------------------------------------------------------------
| Common library of swoole
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Common\Library\Kafka;

use RdKafka\Conf;
use RdKafka\TopicConf;

abstract class AbstractKafka
{
    /**
     * @var string
     */
    protected $metaBrokerList = '127.0.0.1:9092';

    /**
     * @var Conf
     */
    protected $conf;

    /**
     * @var TopicConf
     */
    protected $topicConf;

    /**
     * @var string
     */
    protected $topicName;

    /**
     * @var array
     */
    protected $defaultConfig = [];

    /**
     * @var array
     */
    protected $defaultTopicConfig = [];

    /**
     * @param Conf $conf
     * @return mixed
     */
    abstract public function setConf(Conf $conf);

    /**
     * @param TopicConf $topicConf
     */
    abstract public function setTopicConf(TopicConf $topicConf);

    /**
     * @param $metaBrokerList
     */
    public function setBrokerList($metaBrokerList)
    {
        if(is_array($metaBrokerList)) {
            $metaBrokerList = implode(',', $metaBrokerList);
        }
        if(!empty($metaBrokerList)) {
            $this->metaBrokerList = $metaBrokerList;
            $this->conf->set('metadata.broker.list', $metaBrokerList);
        }
    }

    /**
     * @param array $config
     * @return mixed
     */
    public function setConfig(array $config = [])
    {
        $config = array_merge($this->defaultConfig, $config);
        foreach($config as $key => $value)
        {
            $this->conf->set($key, $value);
            if($key == 'auto.offset.reset')
            {
                $this->getTopicConf()->set($key, $value);
            }
        }
    }

    /**
     * @param array $topicConfig
     * @return mixed
     */
    public function setTopicConfig(array $topicConfig = [])
    {
        $config = array_merge($this->defaultTopicConfig, $topicConfig);
        foreach($config as $key => $value)
        {
            $this->getTopicConf()->set($key, $value);
        }
    }

    /**
     * @param string $topicName
     */
    public function setTopicName(string $topicName)
    {
        $this->topicName = $topicName;
    }

    /**
     * @return string
     */
    public function getTopicName()
    {
        return $this->topicName;
    }

    /**
     * @return Conf
     */
     public function getConf()
     {
         return $this->conf;
     }

    /**
     * @return TopicConf
     */
    public function getTopicConf()
    {
        if(!$this->topicConf) {
            $this->topicConf = new TopicConf();
        }
        return $this->topicConf;
    }
}