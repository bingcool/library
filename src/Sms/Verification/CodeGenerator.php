<?php

declare(strict_types=1);

namespace Swoolefy\Library\Sms\Verification;

use Swoolefy\Library\Sms\Exception\InvalidConfigException;
use Swoolefy\Library\Sms\Exception\SmsException;

/**
 * 验证码生成器。默认密码学安全随机（random_int），可注入随机源便于测试确定性断言。
 *
 * 类型：
 * - number：纯数字
 * - alnum：大写字母 + 数字，剔除易混字符（O/0、I/1/L），降低人工辨认成本
 */
final class CodeGenerator
{
    /** 剔除易混淆字符后的可用字母数字集（用于 alnum） */
    private const ALNUM_POOL = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @param int $length 验证码长度
     * @param string $type number|alnum
     * @param (callable(int, int): int)|null $randomInt 注入随机源 (min,max)→int，便于测试
     */
    private ?\Closure $randomInt;

    public function __construct(
        private int $length = 6,
        private string $type = 'number',
        ?callable $randomInt = null,
    ) {
        // 属性不支持 callable 类型，统一收归为 Closure
        $this->randomInt = $randomInt === null ? null : $randomInt(...);
        if ($this->length < 4 || $this->length > 12) {
            throw new SmsException('Verification code length must be between 4 and 12');
        }

        $this->type = strtolower(trim($this->type));
        if (!in_array($this->type, ['number', 'alnum'], true)) {
            throw new InvalidConfigException(sprintf(
                'Verification code type [%s] must be number or alnum',
                $type
            ));
        }
    }

    /**
     * 生成一个验证码。
     */
    public function generate(): string
    {
        $pool = $this->type === 'alnum' ? self::ALNUM_POOL : '0123456789';
        $max = strlen($pool) - 1;
        $code = '';

        for ($i = 0; $i < $this->length; $i++) {
            $index = $this->randomInt !== null
                ? ($this->randomInt)(0, $max)
                : random_int(0, $max);
            $code .= $pool[$index];
        }

        return $code;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
