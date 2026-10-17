<?php

declare(strict_types=1);

namespace Swoolefy\Library\Mail\Support;

use Swoolefy\Library\Mail\Address;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;

/**
 * 在真正连 SMTP 之前拒绝写错的 mailer。加密和端口必须成对，避免 465 配 STARTTLS 这种发不出去的组合。
 */
final class MailerConfigValidator
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalize(string $name, array $config): array
    {
        $transport = strtolower(trim((string) ($config['transport'] ?? '')));
        if (!in_array($transport, ['smtp', 'sendmail', 'mail', 'log'], true)) {
            throw new InvalidMailerException(sprintf('Unknown mail transport [%s] for [%s]', $transport, $name));
        }

        $from = self::address($config['from'] ?? null, sprintf('Mailer [%s] requires from.address', $name));
        $replyTo = isset($config['reply_to']) ? self::address($config['reply_to'], sprintf('Mailer [%s] reply_to is invalid', $name)) : null;

        $normalized = [
            'transport' => $transport,
            'host' => '',
            'port' => 0,
            'encryption' => '',
            'username' => '',
            'password' => '',
            'timeout' => 10,
            'auth' => false,
            'from' => $from,
            'reply_to' => $replyTo,
            'sendmail_path' => '/usr/sbin/sendmail',
            'dkim' => null,
            'match_from' => false,
        ];

        if ($transport === 'smtp') {
            $normalized = array_merge($normalized, self::smtp($name, $config, $from));
        }

        if ($transport === 'sendmail') {
            $normalized['sendmail_path'] = self::sendmailBinary((string) ($config['path'] ?? '/usr/sbin/sendmail'));
        }

        $normalized['dkim'] = self::dkim($name, $config['dkim'] ?? null);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function smtp(string $name, array $config, Address $from): array
    {
        $host = strtolower(trim((string) ($config['host'] ?? '')));
        $port = (int) ($config['port'] ?? 0);
        $encryption = strtolower(trim((string) ($config['encryption'] ?? '')));
        if ($host === '' || $port < 1) {
            throw new InvalidMailerException(sprintf('Mailer [%s] requires host and port', $name));
        }

        $expected = match ($encryption) {
            'ssl' => 465,
            'tls' => 587,
            '' => 25,
            default => 0,
        };
        if ($expected === 0 || $port !== $expected) {
            throw new InvalidMailerException(sprintf(
                'Mailer [%s] encryption [%s] does not match port %d',
                $name,
                $encryption === '' ? 'none' : $encryption,
                $port
            ));
        }

        if (KnownProviders::isDingtalk($host) && ($port !== 465 || $encryption !== 'ssl')) {
            throw new InvalidMailerException('DingTalk mailbox SMTP must use port 465 and ssl');
        }

        $auth = (bool) ($config['auth'] ?? true);
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        if ($auth && ($username === '' || $password === '')) {
            throw new InvalidMailerException(sprintf('Mailer [%s] requires username and password', $name));
        }
        if ($auth) {
            AddressGuard::assert($username);
        }

        $matchFrom = KnownProviders::requiresMatchingFrom($host);
        if ($matchFrom && strcasecmp($from->email, $username) !== 0) {
            throw new InvalidMailerException(sprintf('Mailer [%s] from.address must match username', $name));
        }

        $timeout = (int) ($config['timeout'] ?? 10);
        if ($timeout < 1) {
            throw new InvalidMailerException(sprintf('Mailer [%s] timeout must be at least 1 second', $name));
        }

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'timeout' => $timeout,
            'auth' => $auth,
            'match_from' => $matchFrom,
        ];
    }

    private static function sendmailBinary(string $path): string
    {
        $path = trim($path);
        $binary = preg_split('/\s+/', $path)[0] ?? '';
        if ($binary === '' || str_contains($binary, '..') || str_contains($binary, "\0")) {
            throw new InvalidMailerException('Mail sendmail path is invalid');
        }

        return $binary;
    }

    /**
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

        $domain = trim((string) ($dkim['domain'] ?? ''));
        if ($domain === '') {
            return null;
        }

        $private = (string) ($dkim['private'] ?? '');
        if ($private === '' || !is_file($private)) {
            throw new InvalidMailerException(sprintf('Mailer [%s] DKIM private key file is missing', $name));
        }

        return [
            'domain' => $domain,
            'selector' => trim((string) ($dkim['selector'] ?? 'default')),
            'private' => $private,
            'passphrase' => (string) ($dkim['passphrase'] ?? ''),
        ];
    }

    private static function address(mixed $value, string $error): Address
    {
        if (!is_array($value) || trim((string) ($value['address'] ?? '')) === '') {
            throw new InvalidMailerException($error);
        }

        try {
            return new Address((string) $value['address'], (string) ($value['name'] ?? ''));
        } catch (InvalidMailMessageException) {
            throw new InvalidMailerException($error);
        }
    }
}
