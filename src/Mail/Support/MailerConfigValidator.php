<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Address;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;

/**
 * 在真正连 SMTP 之前拒绝写错的 mailer。加密方式与端口解耦，仅保留服务商自己的硬规则。
 */
final class MailerConfigValidator
{
    private const BUILTIN_TRANSPORTS = ['smtp', 'sendmail', 'mail', 'log'];

    /**
     * 内置 transport 的完整归一化入口。
     * 先校验 transport 和公共字段，再按 smtp / sendmail 叠加专属规则；
     * 返回的数组可直接被 Transport 与 PhpMailerMailer 消费，不再做二次类型转换。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     * @throws InvalidMailerException 未知 transport 或任一配置项非法
     */
    public static function normalize(string $name, array $config): array
    {
        $transport = self::transportName($name, $config);
        if (!in_array($transport, self::BUILTIN_TRANSPORTS, true)) {
            throw new InvalidMailerException(sprintf('Unknown mail transport [%s] for [%s]', $transport, $name));
        }

        $normalized = array_merge(self::normalizeCommon($name, $config), [
            'host' => '',
            'port' => 0,
            'encryption' => '',
            'autotls' => false,
            'username' => '',
            'password' => '',
            'timeout' => 10,
            'auth' => false,
            'sendmail_path' => '/usr/sbin/sendmail',
            'match_from' => false,
        ]);

        if ($transport === 'smtp') {
            $normalized = array_merge($normalized, self::smtp($name, $config, $normalized['from']));
        }

        if ($transport === 'sendmail') {
            $path = $config['path'] ?? '/usr/sbin/sendmail';
            if (!is_string($path)) {
                throw new InvalidMailerException(sprintf('Mailer [%s] sendmail path is invalid', $name));
            }
            $normalized['sendmail_path'] = self::sendmailBinary($path);
        }

        return $normalized;
    }

    /**
     * 自定义 transport 的公共校验：from 必填、reply_to 可选、DKIM 要么完整要么不配。
     * transport 专属字段（host/port 等）不在这里处理，由自定义创建器自行校验。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalizeCommon(string $name, array $config): array
    {
        $transport = self::transportName($name, $config);

        $from = self::address($config['from'] ?? null, sprintf('Mailer [%s] requires from.address', $name));
        $replyTo = isset($config['reply_to'])
            ? self::address($config['reply_to'], sprintf('Mailer [%s] reply_to is invalid', $name))
            : null;

        return array_merge($config, [
            'transport' => $transport,
            'from' => $from,
            'reply_to' => $replyTo,
            'dkim' => self::dkim($name, $config['dkim'] ?? null),
        ]);
    }

    /**
     * SMTP 专属校验。关键点：
     * - 端口 1..65535 与 encryption 相互独立，仅钉钉保留 465+ssl 硬规则；
     * - auth / autotls 走严格布尔，账号只拒绝控制字符（允许 SES AKIA...、SendGrid apikey）；
     * - 已知服务商校验 from.address 与 username 一致；
     * - autotls 未配置时只在 tls 下开启，避免明文端口被自动升级。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function smtp(string $name, array $config, Address $from): array
    {
        $host = strtolower(self::scalarString($config['host'] ?? '', sprintf('Mailer [%s] host is invalid', $name)));
        if ($host === '') {
            throw new InvalidMailerException(sprintf('Mailer [%s] requires host', $name));
        }

        $port = ConfigValue::integer($config['port'] ?? 0, sprintf('mailers.%s.port', $name));
        if ($port < 1 || $port > 65535) {
            throw new InvalidMailerException(sprintf('Mailer [%s] port must be between 1 and 65535', $name));
        }

        $encryption = strtolower(self::scalarString($config['encryption'] ?? '', sprintf('Mailer [%s] encryption is invalid', $name)));
        if (!in_array($encryption, ['', 'ssl', 'tls'], true)) {
            throw new InvalidMailerException(sprintf(
                'Mailer [%s] encryption must be one of ssl, tls or empty',
                $name
            ));
        }

        if (KnownProviders::isDingtalk($host) && ($port !== 465 || $encryption !== 'ssl')) {
            throw new InvalidMailerException('DingTalk mailbox SMTP must use port 465 and ssl');
        }

        $auth = ConfigValue::boolean($config['auth'] ?? true, sprintf('mailers.%s.auth', $name));
        $username = trim(self::scalarString($config['username'] ?? '', sprintf('Mailer [%s] username is invalid', $name)));
        $password = self::scalarString($config['password'] ?? '', sprintf('Mailer [%s] password is invalid', $name));
        if ($auth && ($username === '' || $password === '')) {
            throw new InvalidMailerException(sprintf('Mailer [%s] requires username and password', $name));
        }
        if ($auth && preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
            throw new InvalidMailerException(sprintf('Mailer [%s] username is invalid', $name));
        }

        $matchFrom = KnownProviders::requiresMatchingFrom($host);
        if ($matchFrom && strcasecmp($from->email, $username) !== 0) {
            throw new InvalidMailerException(sprintf('Mailer [%s] from.address must match username', $name));
        }

        $timeout = ConfigValue::integer($config['timeout'] ?? 10, sprintf('mailers.%s.timeout', $name));
        if ($timeout < 1 || $timeout > 3600) {
            throw new InvalidMailerException(sprintf('Mailer [%s] timeout must be between 1 and 3600 seconds', $name));
        }

        $autotls = array_key_exists('autotls', $config)
            ? ConfigValue::boolean($config['autotls'], sprintf('mailers.%s.autotls', $name))
            : $encryption === 'tls';

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'autotls' => $autotls,
            'username' => $username,
            'password' => $password,
            'timeout' => $timeout,
            'auth' => $auth,
            'match_from' => $matchFrom,
        ];
    }

    /**
     * sendmail 可执行文件必须是无参数、无空白的绝对路径。
     * PHPMailer 会对整段 Sendmail 做 escapeshellcmd 并自行追加 -i -t，
     * 因此路径里带参数（如 -bs）会被转义成错误命令，这里提前拒绝。
     */
    private static function sendmailBinary(string $path): string
    {
        $path = trim($path);
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || preg_match('/\s/', $path) === 1
            || preg_match('#(?:^|/)\.\.(?:$|/)#', $path) === 1
            || str_contains($path, "\0")
        ) {
            throw new InvalidMailerException(sprintf('Mail sendmail path [%s] is invalid', $path));
        }
        if (!is_file($path) || !is_executable($path)) {
            throw new InvalidMailerException(sprintf('Mail sendmail path [%s] is not executable', $path));
        }

        return $path;
    }

    /**
     * DKIM 要么完全不配，要么 domain/selector/private 同时齐全。
     * 私钥必须可读；openssl 可用时先实际解析一次，口令错误或 PEM 损坏在配置阶段就拒绝。
     *
     * @return array{domain: string, selector: string, private: string, passphrase: string}|null
     */
    private static function dkim(string $name, mixed $dkim): ?array
    {
        if ($dkim === null) {
            return null;
        }
        if (!is_array($dkim)) {
            throw new InvalidMailerException(sprintf('Mailer [%s] dkim config must be an array', $name));
        }

        $domain = self::scalarString($dkim['domain'] ?? '', sprintf('Mailer [%s] DKIM domain is required', $name));
        if ($domain === '') {
            throw new InvalidMailerException(sprintf('Mailer [%s] DKIM domain is required', $name));
        }

        $selector = self::scalarString($dkim['selector'] ?? '', sprintf('Mailer [%s] DKIM selector is invalid', $name));
        if (preg_match('/^[A-Za-z0-9._-]+$/', $selector) !== 1) {
            throw new InvalidMailerException(sprintf('Mailer [%s] DKIM selector is invalid', $name));
        }

        $private = self::scalarString($dkim['private'] ?? '', sprintf('Mailer [%s] DKIM private key file is missing', $name));
        if ($private === '' || !is_file($private) || !is_readable($private)) {
            throw new InvalidMailerException(sprintf('Mailer [%s] DKIM private key file is missing', $name));
        }

        $passphrase = self::scalarString($dkim['passphrase'] ?? '', sprintf('Mailer [%s] DKIM passphrase is invalid', $name));
        if (function_exists('openssl_pkey_get_private')) {
            $contents = @file_get_contents($private);
            if ($contents === false || @openssl_pkey_get_private($contents, $passphrase) === false) {
                throw new InvalidMailerException(sprintf('Mailer [%s] DKIM private key cannot be parsed', $name));
            }
        }

        return [
            'domain' => $domain,
            'selector' => $selector,
            'private' => $private,
            'passphrase' => $passphrase,
        ];
    }

    /**
     * 配置里的地址块统一走 Address 校验，并把消息异常转成配置异常（调用方只关心配置错）。
     */
    private static function address(mixed $value, string $error): Address
    {
        if (!is_array($value)) {
            throw new InvalidMailerException($error);
        }
        $address = self::scalarString($value['address'] ?? '', $error);
        if ($address === '') {
            throw new InvalidMailerException($error);
        }

        try {
            return new Address($address, self::scalarString($value['name'] ?? '', $error));
        } catch (InvalidMailMessageException) {
            throw new InvalidMailerException($error);
        }
    }

    /**
     * 只接受字符串或数字标量并 trim；数组、对象、布尔等形状直接报配置错误，
     * 避免后续 (string) 转换产生难以定位的 TypeError。
     */
    private static function scalarString(mixed $value, string $error): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidMailerException($error);
        }

        return trim((string) $value);
    }

    /**
     * transport 必须是非空字符串，统一小写去空白；大小写不影响内置匹配。
     *
     * @param array<string, mixed> $config
     */
    private static function transportName(string $name, array $config): string
    {
        $transport = $config['transport'] ?? '';
        if (!is_string($transport)) {
            throw new InvalidMailerException(sprintf('Mailer [%s] transport is invalid', $name));
        }

        $transport = strtolower(trim($transport));
        if ($transport === '') {
            throw new InvalidMailerException(sprintf('Mailer [%s] transport is required', $name));
        }

        return $transport;
    }
}
