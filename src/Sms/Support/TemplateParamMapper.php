<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Support;

use Swoolefy\Library\Sms\Exception\InvalidProviderException;

/**
 * 把网关无关的关联参数（`['code'=>'123456','name'=>'张三']`）适配成各厂商要求的模板参数格式。
 *
 * 差异全部封在这里，业务只面向 `SmsMessage::$params`（关联数组）：
 * - 阿里云：`TemplateParam` 为 JSON **对象**（键名需与模板变量一致），这里返回原关联数组，由网关 `json_encode`。
 * - 腾讯云：`TemplateParamSet` 为**字符串列表**，按模板占位顺序取值（`{1}`、`{2}`…）。
 *   未配置顺序时沿用插入顺序；配置 `param_order` 后按键名取值为厂商占位顺序。
 */
final class TemplateParamMapper
{
    /**
     * 阿里云：保留键名，值为字符串。
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public static function toAliyun(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $out[(string) $key] = self::stringify($value);
        }

        return $out;
    }

    /**
     * 腾讯云：生成字符串列表。
     *
     * - `$order` 为空：按 params 插入顺序取值
     * - `$order` 非空：严格按键名顺序取值，缺失或多余的参数直接报错，避免占位错位
     *
     * @param array<string, mixed> $params
     * @param list<string> $order
     * @return list<string>
     */
    public static function toTencent(array $params, array $order = []): array
    {
        if ($order === []) {
            $out = [];
            foreach ($params as $value) {
                $out[] = self::stringify($value);
            }

            return $out;
        }

        $out = [];
        foreach ($order as $key) {
            if (!array_key_exists($key, $params)) {
                throw new InvalidProviderException("Tencent template param [{$key}] is missing from message params");
            }
            $out[] = self::stringify($params[$key]);
        }

        $extra = array_diff(array_map('strval', array_keys($params)), $order);
        if ($extra !== []) {
            throw new InvalidProviderException(sprintf(
                'Tencent template params [%s] are not covered by param_order',
                implode(', ', $extra)
            ));
        }

        return $out;
    }

    /**
     * 标量转字符串；bool 转 'true'/'false'，null 转空串，array 转 JSON。
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
