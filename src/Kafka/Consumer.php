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
use RdKafka\KafkaConsumer;

/**
 * Class ConsumerKafka
 * @package Common\Library\Kafka
 */
class Consumer extends AbstractKafka
{
    /**
     * @var KafkaConsumer
     */
    protected $rdKafkaConsumer;

    /**
     * @var string
     */
    protected $groupId;

    /**
     * @var bool
     */
    protected $hasSubject = false;

    /**
     * @var array
     */
    protected $rebalanceCbCallbacks = [];

    /**
     * global config
     * @var array
     */
    protected $defaultProperty = [
        'enable.auto.commit' => 1,
        'auto.commit.interval.ms' => 200,
        'auto.offset.reset' => 'earliest',
        'session.timeout.ms' => 45 * 1000,
        'max.poll.interval.ms' => 600 * 1000
    ];

    /**
     * ConsumerKafka constructor.
     * @param string $metaBrokerList
     * @param string $topicName
     */
    public function __construct(
        $metaBrokerList = '',
        $topicName = ''
    )
    {
        $this->conf = new \RdKafka\Conf();
        $this->setBrokerList($metaBrokerList);
        $this->setGlobalProperty();
        $this->setRebalanceCb();
        $this->topicName = $topicName;
    }

    /**
     * @param $groupId
     */
    public function setGroupId($groupId)
    {
        $this->conf->set('group.id', $groupId);
        $this->groupId = $groupId;
    }

    /**
     * @param string $value
     */
    public function setAutoOffsetReset(string $value)
    {
        $this->getTopicConf()->set('auto.offset.reset', $value);
    }

    /**
     * @param callable|null $callback
     */
    public function setRebalanceCb(callable $callback = null)
    {
        if (!$callback) {
            $callback = $this->getRebalanceCbCallBack();
        }
        $this->conf->setRebalanceCb($callback);
    }

    /**
     * @return callable
     */
    protected function getRebalanceCbCallBack(): callable
    {
        return $callBack = function (\RdKafka\KafkaConsumer $kafka, $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);
                    $callback = $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS] ?? '';
                    if ($callback instanceof \Closure) {
                        $callback->call($this, $partitions);
                    }
                    break;
                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $kafka->assign(null);
                    $callback = $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS] ?? '';
                    if ($callback instanceof \Closure) {
                        $callback->call($this, $partitions);
                    }
                    break;
                default:
                    throw new \RdKafka\Exception("kafka ConsumerKafka RebalanceCb ErrorCode={$err}");
            }
        };
    }

    /**
     * @param \Closure $callback
     * @return bool
     */
    public function setAssignPartitionsCallback(\Closure $callback)
    {
        $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS] = $callback;
        return true;
    }

    /**
     * @param \Closure $callback
     * @return bool
     */
    public function setRevokePartitionsCallback(\Closure $callback)
    {
        $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS] = $callback;
        return true;
    }

    /**
     * @param Conf $conf
     */
    public function setGlobalConf(Conf $conf)
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
     * @param string|null $topicName
     * @return KafkaConsumer
     * @throws Throwable
     */
    public function subject(string $topicName = null)
    {
        if($this->hasSubject) {
            return $this->rdKafkaConsumer;
        }

        if (!$this->groupId) {
            throw new \RdKafka\Exception('Kafka ConsumerKafka Missing GroupId');
        }

        if ($topicName) {
            $this->topicName = $topicName;
        }

        try {
            $rdKafkaConsumer = $this->getRdKafkaConsumer();
            $rdKafkaConsumer->subscribe([$this->topicName]);
        } catch (\Throwable $throwable) {
            throw $throwable;
        }

        $this->hasSubject = true;
        if(empty($this->rdKafkaConsumer)) {
            $this->rdKafkaConsumer = $rdKafkaConsumer;
        }
        return $this->rdKafkaConsumer;
    }

    /**
     * @param int $timeout
     * @return \RdKafka\Message
     */
    public function consume(int $timeout = 10*1000): \RdKafka\Message {
        $this->subject();
        return $this->rdKafkaConsumer->consume($timeout);
    }

    /**
     * setTopicConfToConf
     * @return void
     */
    protected function setTopicConfToConf()
    {
        $topicConf = $this->getTopicConf();
        $this->conf->setDefaultTopicConf($topicConf);
    }

    /**
     * @return KafkaConsumer
     */
    protected function getRdKafkaConsumer()
    {
        $this->setTopicConfToConf();
        $this->rdKafkaConsumer = new KafkaConsumer($this->conf);
        return $this->rdKafkaConsumer;
    }
}