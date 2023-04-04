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

namespace Common\Library\Amqp;

use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

class AmqpStreamConnectionFactory {
    /**
     * @param array $hosts
     * @param array $options
     * @return \PhpAmqpLib\Connection\AbstractConnection
     * @throws \Throwable
     */
    public static function create(array $hosts, array $options) {
        if (!is_array($hosts) || count($hosts) < 1) {
            throw new \InvalidArgumentException(
                'An array of hosts are required when attempting to create a connection'
            );
        }

        foreach ($hosts as $hostItem) {
            self::validate_host($hostItem);
            $host = $hostItem['host'];
            $port = $hostItem['port'];
            $user = $hostItem['user'];
            $password = $hostItem['password'];
            $vhost = isset($hostItem['vhost']) ? $hostItem['vhost'] : '/';
            try {
                $amqpConfig = new AMQPConnectionConfig();
                $amqpConfig->setHost($host);
                $amqpConfig->setPort($port);
                $amqpConfig->setUser($user);
                $amqpConfig->setPassword($password);
                $amqpConfig->setVhost($vhost);
                isset($options['insist']) ? $amqpConfig->setInsist($options['insist']) : $amqpConfig->setInsist(false);
                isset($options['is_lazy']) ? $amqpConfig->setIsLazy($options['is_lazy']) : $amqpConfig->setIsLazy(false);
                isset($options['io_type']) ? $amqpConfig->setIoType($options['io_type']) : $amqpConfig->setIoType(AMQPConnectionConfig::IO_TYPE_STREAM) ;
                isset($options['login_method']) ? $amqpConfig->setLoginMethod($options['login_method']) : $amqpConfig->setLoginMethod('AMQPLAIN');
                isset($options['login_response']) ? $amqpConfig->setLoginResponse($options['login_response']) : $amqpConfig->setLoginResponse(null);
                isset($options['locale']) ? $amqpConfig->setLocale($options['locale']) : $amqpConfig->setLocale('en_US');
                isset($options['connection_timeout']) ? $amqpConfig->setConnectionTimeout($options['connection_timeout']) : $amqpConfig->setConnectionTimeout(5.0);
                isset($options['read_write_timeout']) ? $amqpConfig->setReadTimeout($options['read_write_timeout']) : $amqpConfig->setReadTimeout(5.0);
                isset($options['context']) ? $amqpConfig->setStreamContext($options['context']) : $amqpConfig->setStreamContext(null);
                isset($options['keepalive']) ? $amqpConfig->setKeepalive($options['keepalive']) : $amqpConfig->setKeepalive(false);
                isset($options['heartbeat']) ? $amqpConfig->setHeartbeat($options['heartbeat']) : $amqpConfig->setHeartbeat(0);
                isset($options['channel_rpc_timeout']) ? $amqpConfig->setChannelRPCTimeout($options['channel_rpc_timeout']) : $amqpConfig->setChannelRPCTimeout(0);
                isset($options['is_secure']) ? $amqpConfig->setIsSecure($options['is_secure']) : $amqpConfig->setIsSecure(false);
                $connection = AMQPConnectionFactory::create($amqpConfig);
                return $connection;
            } catch (\Throwable $e) {
                $latestException = $e;
            }
        }

        if (isset($latestException)) {
            throw $latestException;
        }
    }

    /**
     * @param $host
     * @return void
     */
    protected static function validate_host($host)
    {
        if (!isset($host['host'])) {
            throw new \InvalidArgumentException("'host' key is required.");
        }
        if (!isset($host['port'])) {
            throw new \InvalidArgumentException("'port' key is required.");
        }
        if (!isset($host['user'])) {
            throw new \InvalidArgumentException("'user' key is required.");
        }
        if (!isset($host['password'])) {
            throw new \InvalidArgumentException("'password' key is required.");
        }
    }
}