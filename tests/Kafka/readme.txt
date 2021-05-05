参考https://www.cnblogs.com/angelyan/p/14445710.html



firewall-cmd --zone=public --add-port=9092/tcp --permanent


firewall-cmd --zone=public --add-port=2181/tcp --permanent

docker run -d --name kafka \
-p 9092:9092 \
-e KAFKA_BROKER_ID=0 \
-e KAFKA_ZOOKEEPER_CONNECT=10.0.2.15:2181 \
-e KAFKA_ADVERTISED_LISTENERS=PLAINTEXT://192.168.99.103:9092 \
-e KAFKA_LISTENERS=PLAINTEXT://0.0.0.0:9092 wurstmeister/kafka

// 创建topic mykafka
./bin/kafka-topics.sh --create --zookeeper 192.168.99.103:2181 --replication-factor 1 --partitions 1 --topic mykafka

// 查看topic情况
./bin/kafka-topics.sh --zookeeper 192.168.99.103:2181 --describe --topic mykafka

// topic扩大分区
./bin/kafka-topics.sh --zookeeper 192.168.99.103:2181 -alter --partitions 4 --topic mykafka


#运行一个生产者
./bin/kafka-console-producer.sh --broker-list localhost:9092 --topic mykafka
#运行一个消费者
./bin/kafka-console-consumer.sh --zookeeper zookeeper:2181 --topic mykafka --from-beginning


资料：
https://segmentfault.com/a/1190000039768128?utm_source=tag-newest


配置项说明
https://www.cnblogs.com/weixiuli/p/6413109.html
https://github.com/edenhill/librdkafka/blob/master/CONFIGURATION.md