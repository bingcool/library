<?php
// 包名, 完全限定命名空间
$package = "Common.Library.Tests.Protobuf";
// 当前模块的proto文件名称
$proto_name = "book.proto";

// 当前目录
$current_dir = getcwd();
// php命名空间
$name_space = str_replace('.', '/', $package);
$last_name_space = array_pop(explode('/', $name_space));

// 输出位置
$php_out = substr($current_dir, 0, strpos($current_dir, $last_name_space)) . "$last_name_space";
// protoc 执行命令
$proto_shell = "protoc -I={$current_dir} --php_out={$php_out} {$proto_name}";

echo $proto_shell;