<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Exception;

/**
 * 当前 driver 不支持该方法。
 *
 * 例：weixin_mini 调用 getAuthUrl；qq 调用 getWebAuthUrl；非小程序调用 getSessionKey。
 * 用于在编译期无法表达的「按 driver 能力矩阵」上做运行时守卫。
 */
final class UnsupportedOauthCapabilityException extends OauthException
{
}
