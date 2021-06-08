 CREATE DATABASE IF NOT EXISTS bingcool
 DEFAULT CHARACTER SET utf8;


CREATE TABLE `tbl_order`(
    `order_id` bigint(20) not null default 0 COMMENT '订单id',
    `user_id` int(11) not null default 0 COMMENT 'user_id',
    `order_amount` float(11,2) not null default 0.00 COMMENT '订单金额',
    `order_product_ids` text not null COMMENT '订单关联产品ID',
    `order_status` tinyint(2) not null default 1 COMMENT '订单状态',
    `remark` varchar(256) not null default '' COMMENT '备注',
    `create_time` datetime not null DEFAULT CURRENT_TIMESTAMP COMMENT '修改时间',
    `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;

CREATE TABLE `tbl_app_info` (
 `id` bigint(21) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
 `app_id` varchar(30) DEFAULT NULL COMMENT '应用编码',
 `app_name` varchar(30) DEFAULT NULL COMMENT '应用名称',
 `package_name` varchar(100) DEFAULT NULL COMMENT '包名',
 `version` int(11) DEFAULT NULL COMMENT '版本号',
 `version_name` varchar(20) DEFAULT NULL COMMENT '版本名',
 `icon_url` varchar(512) DEFAULT NULL COMMENT 'Icon地址',
 `download_url` varchar(512) DEFAULT NULL COMMENT '下载地址',
 `summary` varchar(512) DEFAULT NULL COMMENT '摘要',
 `desc` varchar(512) DEFAULT NULL COMMENT '描述信息',
 `app_status` int(4) DEFAULT '0' COMMENT '状态 0：可用，1：删除',
 `create_time` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
 `create_user` varchar(30) DEFAULT NULL COMMENT '创建人',
 `modify_time` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '修改时间',
 `modify_user` varchar(30) DEFAULT NULL COMMENT '修改人',
 PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;