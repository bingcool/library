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
use RdKafka\ProducerTopic;

/**
 * Class Producer
 * @package Common\Library\Kafka
 */
class Producer extends AbstractKafka
{
    /**
     * @var \RdKafka\Producer
     */
    protected $rdKafkaProducer;

    /**
     * @var ProducerTopic
     */
    protected $producerTopic;

    /**
     * global config
     * @var array
     */
    protected $defaultConfig = [
        'enable.idempotence' => 0,
        'message.send.max.retries' => 10
    ];

    /**
     * topic config
     * @var array
     */
    protected $defaultTopicConfig = [];

    /**
     * Producer constructor.
     * @param mixed $metaBrokerList
     * @param string $topicName
     */
    public function __construct($metaBrokerList = '', string $topicName = '')
    {
        $this->conf = new \RdKafka\Conf();
        $this->setBrokerList($metaBrokerList);
        $this->setConfig();
        $this->topicName = $topicName;
    }

    /**
     * @param Conf $conf
     */
    public function setConf(Conf $conf)
    {
        $this->conf = $conf;
    }

    /**
     * @param TopicConf $topicConf
     */
    public function setTopicConf(TopicConf $topicConf)
    {
        $this->topicConf = $topicConf;
    }

    /**
     * @return TopicConf
     */
    public function getTopicConf()
    {
        if (!$this->topicConf) {
            $this->topicConf = new TopicConf();
        }
        $this->setTopicConfig();
        return $this->topicConf;
    }

    /**
     * @return \RdKafka\Producer
     */
    public function getRdKafkaProducer(): \RdKafka\Producer
    {
        if (!$this->rdKafkaProducer) {
            $this->rdKafkaProducer = new \RdKafka\Producer($this->conf);
        }
        return $this->rdKafkaProducer;
    }

    /**
     * @return ProducerTopic
     */
    public function getProducerTopic()
    {
        if (!$this->producerTopic) {
            $this->producerTopic = $this->getRdKafkaProducer()->newTopic($this->topicName, $this->getTopicConf() ?? null);
        }

        return $this->producerTopic;
    }

    /**
     * @param string $payload
     * @param int $timeoutMs
     * @param string|null $key 同一个key的信息将会配分配到相同的分区，所有比如对于以下orderId顺序处理事件，传入orderId即可
     * @param int $partition
     * @param int $msgFlag
     * @return void
     * @throws \RdKafka\Exception
     */
    public function produce(
        string $payload,
        int $timeoutMs = 5000,
        string $key = null,
        $partition = RD_KAFKA_PARTITION_UA,
        $msgFlag = 0
    )
    {
        if (!$this->topicName) {
            throw new \RdKafka\Exception('Kafka Producer Missing topicName');
        }
        $this->getRdKafkaProducer();
        $this->rdKafkaProducer->addBrokers($this->metaBrokerList);
        $this->producerTopic = $this->getProducerTopic();
        $this->producerTopic->produce($partition, $msgFlag, $payload, $key);
        $this->rdKafkaProducer->poll(0);
        $this->rdKafkaProducer->flush($timeoutMs);
    }

    /**
     * @param string $payload
     * @param int $timeoutMs
     * @param string|null $key
     * @param array|null $headers
     * @param int $partition
     * @param int $msgFlag
     * @throws \RdKafka\Exception
     */
    public function producev(
        string $payload,
        int $timeoutMs = 5000,
        string $key = null,
        $headers = null,
        $partition = RD_KAFKA_PARTITION_UA,
        $msgFlag = 0
    )
    {
        if (!$this->topicName) {
            throw new \RdKafka\Exception('Kafka Producer Missing topicName');
        }
        $this->getRdKafkaProducer();
        $this->rdKafkaProducer->addBrokers($this->metaBrokerList);
        $this->producerTopic = $this->getProducerTopic();
        $this->producerTopic->producev($partition, $msgFlag, $payload, $key ?? null, $headers ?? null, $timeoutMs ?? null);
        $this->rdKafkaProducer->poll(0);
        $this->rdKafkaProducer->flush($timeoutMs);
    }

}

