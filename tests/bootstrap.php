<?php
include_once dirname(__DIR__) . "/vendor/autoload.php";
date_default_timezone_set('Asia/Shanghai');

// CurlProxy 等模块依赖 swoolefy 的 Context/LogManager；同级仓库存在时加载框架 autoload
$swoolefyAutoload = dirname(__DIR__, 2) . '/swoolefy/vendor/autoload.php';
if (is_file($swoolefyAutoload)) {
    require_once $swoolefyAutoload;
}

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
