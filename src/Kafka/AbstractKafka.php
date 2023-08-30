<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
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
    protected $defaultProperty = [];

    /**
     * @var array
     */
    protected $globalProperty = [];

    /**
     * @var array
     */
    protected $defaultTopicProperty = [];

    /**
     * @param Conf $conf
     * @return mixed
     */
    abstract public function setGlobalConf(Conf $conf);

    /**
     * @param TopicConf $topicConf
     */
    abstract public function setTopicConf(TopicConf $topicConf);

    /**
     * @param $metaBrokerList
     */
    public function setBrokerList($metaBrokerList)
    {
        if (is_array($metaBrokerList)) {
            $metaBrokerList = implode(',', $metaBrokerList);
        }
        if (!empty($metaBrokerList)) {
            $this->metaBrokerList = $metaBrokerList;
            $this->conf->set('metadata.broker.list', $metaBrokerList);
        }
    }

    /**
     * @param array $property
     * @return mixed
     */
    public function setGlobalProperty(array $property = [])
    {
        $properties = array_merge($this->defaultProperty, $property);
        $this->globalProperty = $properties;
        foreach ($properties as $key => $value) {
            $this->conf->set($key, $value);
            if ($key == 'auto.offset.reset') {
                $this->getTopicConf()->set($key, $value);
            }
        }
    }

    /**
     * @param array $property
     * @return mixed
     */
    public function setTopicProperty(array $property = [])
    {
        $properties = array_merge($this->defaultTopicProperty, $property);
        foreach ($properties as $key => $value) {
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
    public function getTopicName(): string
    {
        return $this->topicName;
    }

    /**
     * @return Conf
     */
    public function getGlobalConf(): Conf
    {
        return $this->conf;
    }

    /**
     * @return TopicConf
     */
    public function getTopicConf(): TopicConf
    {
        if (!$this->topicConf) {
            $this->topicConf = new TopicConf();
        }
        return $this->topicConf;
    }
}