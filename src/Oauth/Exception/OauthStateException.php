<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Exception;

/**
 * OAuth state 校验失败（防 CSRF）。
 *
 * 来源：Yurun Base::checkState 失败抛出的 InvalidArgumentException，
 * 或 FakeOauthClient 在 storeState ≠ 回调 state 时主动抛出。
 * 业务应引导用户重新发起授权，而非重试同一回调。
 */
final class OauthStateException extends OauthException
{
}
