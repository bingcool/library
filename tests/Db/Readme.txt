https://www.runoob.com/docker/docker-install-mysql.html

docker run -itd --name mysql -p 3306:3306 -e MYSQL_ROOT_PASSWORD=123456 mysql

进入docker容器：
终端执行；mysql -h 127.0.0.1 -u root -p
Enter password: 123456

// mysql-8.0+
// PDOException: PDO::__construct(): The server requested authentication method unknown to the client [caching_sha2_password]

USE bingcool;
--使用 mysql 数据库
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY '123456';
ALTER USER root@localhost IDENTIFIED WITH mysql_native_password BY '123456';
--修改身份验证插件，假设用户名为 root，密码为 12345678（请改为你自己的用户名和密码）。
FLUSH PRIVILEGES;
--使更改生效
