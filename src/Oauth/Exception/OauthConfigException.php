<?php

declare(strict_types=1);

namespace Swoolefy\Library\Oauth\Exception;

/**
 * 配置缺失或不合法。
 *
 * 例如：缺 app_id / app_secret / callback_url、支付宝缺私钥、企业微信缺 agent_id。
 * 在 Factory 构造阶段抛出，避免带着空凭证打到第三方。
 */
final class OauthConfigException extends OauthException
{
}
