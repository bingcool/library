<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swoolefy\Library\Mail\Address;
use Swoolefy\Library\Mail\Config\MailConfig;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\TemplateNotFoundException;
use Swoolefy\Library\Mail\MailerManager;
use Swoolefy\Library\Mail\MailFactory;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\PhpMailerMailer;
use Swoolefy\Library\Mail\Support\AttachmentNormalizer;
use Swoolefy\Library\Mail\Support\BlockingTransportWarning;
use Swoolefy\Library\Mail\Support\ErrorRedactor;
use Swoolefy\Library\Mail\Support\MailerConfigValidator;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\Template\TemplateRenderer;
use Swoolefy\Library\Mail\Transport\LogTransport;
use Swoolefy\Library\Mail\Transport\SmtpTransport;
use Swoolefy\Library\Mail\ValueObject\MailResult;

final class P2RegressionTest extends TestCase
{
    public function testSmtpPortAndEncryptionAreIndependent(): void
    {
        $cases = [
            [1025, '', '', false],
            [2525, 'tls', PHPMailer::ENCRYPTION_STARTTLS, true],
            [25, 'tls', PHPMailer::ENCRYPTION_STARTTLS, true],
            [587, 'ssl', PHPMailer::ENCRYPTION_SMTPS, false],
        ];

        foreach ($cases as [$port, $encryption, $secure, $autoTls]) {
            $config = MailerConfigValidator::normalize('relay', $this->smtpConfig([
                'port' => $port,
                'encryption' => $encryption,
            ]));

            self::assertSame($port, $config['port']);
            self::assertSame($encryption, $config['encryption']);

            $box = new PHPMailer(true);
            (new SmtpTransport($config))->apply($box);
            self::assertSame($port, $box->Port);
            self::assertSame($secure, $box->SMTPSecure);
            self::assertSame($autoTls, $box->SMTPAutoTLS);
        }
    }

    public function testAutotlsCanBeDisabledExplicitly(): void
    {
        $config = MailerConfigValidator::normalize('relay', $this->smtpConfig([
            'port' => 2525,
            'encryption' => 'tls',
            'autotls' => false,
        ]));

        self::assertFalse($config['autotls']);
    }

    public function testCcOnlyAndBccOnlyMessagesAreAccepted(): void
    {
        $manager = $this->logManager();

        self::assertSame('log', $manager->send(
            (new MailMessage())->cc('cc@example.com')->subject('s')->text('cc only')
        )->transport);
        self::assertSame('log', $manager->send(
            (new MailMessage())->bcc('bcc@example.com')->subject('s')->text('bcc only')
        )->transport);
    }

    public function testBooleanConfigAcceptsExplicitStringForms(): void
    {
        foreach (['false', '0', 'no', 'off'] as $value) {
            $config = MailerConfigValidator::normalize('relay', $this->smtpConfig(['auth' => $value]));
            self::assertFalse($config['auth'], $value);
        }
        foreach (['true', '1', 'yes', 'on'] as $value) {
            $config = MailerConfigValidator::normalize('relay', $this->smtpConfig(['auth' => $value]));
            self::assertTrue($config['auth'], $value);
        }

        $this->expectException(InvalidMailerException::class);
        MailerConfigValidator::normalize('relay', $this->smtpConfig(['auth' => 'maybe']));
    }

    public function testSendReturningFalseBecomesTransportException(): void
    {
        $box = new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                $this->ErrorInfo = '535 denied for secret-key';

                return false;
            }
        };
        $mailer = $this->smtpMailer($box);

        try {
            $mailer->send($this->baseMessage());
            self::fail('false return should throw');
        } catch (MailTransportException $e) {
            self::assertStringContainsString('***', $e->getMessage());
            self::assertStringNotContainsString('secret-key', $e->getMessage());
        }
    }

    public function testFillStagePhpMailerExceptionBecomesInvalidMessage(): void
    {
        $box = new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function setFrom($address, $name = '', $auto = true)
            {
                throw new \PHPMailer\PHPMailer\Exception('bad from for secret-key');
            }
        };
        $mailer = $this->smtpMailer($box);

        try {
            $mailer->send($this->baseMessage());
            self::fail('fill exception should throw');
        } catch (InvalidMailMessageException $e) {
            self::assertStringNotContainsString('secret-key', $e->getMessage());
        }
    }

    public function testDkimRequiresCompleteAndReadableKey(): void
    {
        $key = $this->dkimKey();

        $invalid = [
            ['selector' => 'mail', 'private' => $key],
            ['domain' => 'example.com', 'private' => $key],
            ['domain' => 'example.com', 'selector' => 'bad selector', 'private' => $key],
            ['domain' => 'example.com', 'selector' => 'mail', 'private' => '/nonexistent/key.pem'],
        ];
        foreach ($invalid as $dkim) {
            try {
                MailerConfigValidator::normalize('relay', $this->smtpConfig(['dkim' => $dkim]));
                self::fail('dkim should be rejected');
            } catch (InvalidMailerException) {
            }
        }

        $normalized = MailerConfigValidator::normalize('relay', $this->smtpConfig([
            'dkim' => ['domain' => 'example.com', 'selector' => 'mail', 'private' => $key],
        ]));
        self::assertSame('example.com', $normalized['dkim']['domain']);
        self::assertSame('mail', $normalized['dkim']['selector']);
    }

    public function testDkimInvalidPemIsRejected(): void
    {
        if (!function_exists('openssl_pkey_get_private')) {
            self::markTestSkipped('openssl is not available');
        }

        $file = tempnam(sys_get_temp_dir(), 'mail-dkim-');
        self::assertIsString($file);
        file_put_contents($file, 'not a private key');

        $this->expectException(InvalidMailerException::class);
        MailerConfigValidator::normalize('relay', $this->smtpConfig([
            'dkim' => ['domain' => 'example.com', 'selector' => 'mail', 'private' => $file],
        ]));
    }

    public function testSendmailPathMustBePlainAbsoluteExecutable(): void
    {
        foreach (['sendmail', './sendmail', '/usr/sbin/sendmail -bs', '/tmp/../bin/sendmail', '/nonexistent/sendmail', sys_get_temp_dir()] as $path) {
            try {
                MailerConfigValidator::normalize('local', [
                    'transport' => 'sendmail',
                    'path' => $path,
                    'from' => ['address' => 'a@example.com'],
                ]);
                self::fail('sendmail path should be rejected: ' . $path);
            } catch (InvalidMailerException) {
            }
        }

        $normalized = MailerConfigValidator::normalize('local', [
            'transport' => 'sendmail',
            'path' => PHP_BINARY,
            'from' => ['address' => 'a@example.com'],
        ]);
        self::assertSame(PHP_BINARY, $normalized['sendmail_path']);
    }

    public function testAddressesAreTrimmedBeforeDedupAndProviderCheck(): void
    {
        self::assertSame('user@example.com', (new Address(' user@example.com '))->email);

        $message = (new MailMessage())
            ->to(' User@Example.com ')
            ->to('user@example.com')
            ->cc(' USER@example.com ')
            ->subject('s')
            ->text('hi');
        self::assertCount(1, $message->toList());
        self::assertCount(0, $message->ccList());

        $normalized = MailerConfigValidator::normalize('qq', [
            'transport' => 'smtp',
            'host' => 'smtp.qq.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => ' a@qq.com ',
            'password' => 'auth-code-16',
            'from' => ['address' => ' a@qq.com ', 'name' => 'A'],
        ]);
        self::assertSame('a@qq.com', $normalized['username']);
        self::assertSame('a@qq.com', $normalized['from']->email);
    }

    public function testEmptyOrInvalidTemplateRootNeverFallsBackToCwd(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/welcome.html', 'from cwd');
        $cwd = getcwd();
        self::assertIsString($cwd);

        chdir($dir);
        try {
            try {
                (new TemplateRenderer(''))->render('welcome', []);
                self::fail('empty view_path must not read cwd');
            } catch (TemplateNotFoundException) {
            }
        } finally {
            chdir($cwd);
        }

        $file = $dir . '/not-a-directory.html';
        file_put_contents($file, 'x');
        $this->expectException(TemplateNotFoundException::class);
        (new TemplateRenderer($file))->render('welcome', []);
    }

    public function testAttachmentLimitUsesActualReadBytes(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/big.bin';
        file_put_contents($file, '0123456789');
        $message = (new MailMessage())->to('user@example.com')->attach($file);

        $this->expectException(InvalidMailMessageException::class);
        (new AttachmentNormalizer())->normalize($message, 5);
    }

    public function testAttachmentBytesAreSnapshottedBeforeSending(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/a.bin';
        file_put_contents($file, 'before');
        $message = (new MailMessage())->to('user@example.com')->attach($file);

        $normalized = (new AttachmentNormalizer())->normalize($message, 100);
        file_put_contents($file, 'after');

        self::assertSame('before', $normalized['attachments'][0]['data']);
    }

    public function testProcStatZeroFileCannotBypassLimit(): void
    {
        $contents = @file_get_contents('/proc/self/environ');
        if ($contents === false || strlen($contents) <= 1) {
            self::markTestSkipped('procfs is not available');
        }

        $message = (new MailMessage())->to('user@example.com')->attach('/proc/self/environ');
        $this->expectException(InvalidMailMessageException::class);
        (new AttachmentNormalizer())->normalize($message, 1);
    }

    public function testShortPasswordsAreAlwaysRedacted(): void
    {
        $redactor = new ErrorRedactor();
        self::assertSame('denied for ***', $redactor->clean('denied for abc', ['abc']));
        self::assertSame('x *** y', $redactor->clean('x abcdef y', ['abc', 'abcdef']));
        self::assertSame('msg', $redactor->clean('msg', ['']));

        $box = new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                throw new \PHPMailer\PHPMailer\Exception('denied for abc');
            }
        };
        $mailer = $this->smtpMailer($box, ['password' => 'abc']);

        try {
            $mailer->send($this->baseMessage());
            self::fail('transport failure should throw');
        } catch (MailTransportException $e) {
            self::assertSame('denied for ***', $e->getMessage());
        }
    }

    public function testAllFailurePathsAreLoggedWithLevelsAndFields(): void
    {
        $logger = new RecordingLogger();
        $manager = $this->logManager([], $logger);

        $manager->send($this->baseMessage());
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('log', $logger->records[0]['context']['mailer']);
        self::assertSame('log', $logger->records[0]['context']['transport']);
        self::assertSame('l***@example.com', $logger->records[0]['context']['from']);
        self::assertSame(['u***@example.com'], $logger->records[0]['context']['to']);
        self::assertSame('default', $logger->records[0]['context']['source']);
        self::assertNull($logger->records[0]['context']['exception']);
        self::assertArrayHasKey('duration_ms', $logger->records[0]['context']);

        try {
            $manager->send((new MailMessage())->subject('s')->text('no recipients'));
            self::fail('message error should throw');
        } catch (InvalidMailMessageException) {
        }
        self::assertSame('warning', $logger->records[1]['level']);
        self::assertSame(InvalidMailMessageException::class, $logger->records[1]['context']['exception']);

        try {
            $manager->send($this->baseMessage()->via('missing'));
            self::fail('unknown mailer should throw');
        } catch (InvalidMailerException) {
        }
        self::assertSame('warning', $logger->records[2]['level']);
        self::assertSame('missing', $logger->records[2]['context']['mailer']);
        self::assertNull($logger->records[2]['context']['transport']);

        $factory = new MailFactory();
        $factory->extend('boom', static fn (): MailerInterface => new class implements MailerInterface {
            public function send(MailMessage $message): MailResult
            {
                throw new MailTransportException('down');
            }

            public function transport(): string
            {
                return 'boom';
            }
        });
        $transportManager = new MailerManager([
            'default_mailer' => 'boom',
            'mailers' => ['boom' => ['transport' => 'boom', 'from' => ['address' => 'a@example.com']]],
        ], $logger, $factory);
        try {
            $transportManager->send($this->baseMessage());
            self::fail('transport error should throw');
        } catch (MailTransportException) {
        }
        self::assertSame('error', $logger->records[3]['level']);
        self::assertSame(MailTransportException::class, $logger->records[3]['context']['exception']);
    }

    public function testLogContextCapsRecipientLists(): void
    {
        $logger = new RecordingLogger();
        $manager = $this->logManager([], $logger);
        $message = (new MailMessage())->subject('s')->text('hi')->via('log');
        for ($i = 0; $i < 12; $i++) {
            $message->to(sprintf('user%d@example.com', $i));
        }

        $manager->send($message);

        self::assertCount(10, $logger->records[0]['context']['to']);
        self::assertSame(12, $logger->records[0]['context']['recipients']);
        self::assertSame('message', $logger->records[0]['context']['source']);
        self::assertArrayNotHasKey('bcc', $logger->records[0]['context']);
    }

    public function testNumericConfigStringsAreStrict(): void
    {
        $invalid = [
            fn () => MailerConfigValidator::normalize('relay', $this->smtpConfig(['port' => '465abc'])),
            fn () => MailerConfigValidator::normalize('relay', $this->smtpConfig(['timeout' => '10s'])),
            fn () => (new MailConfig(['attachments' => ['max_bytes' => '20MB']]))->attachmentMaxBytes(),
            fn () => (new MailConfig(['attachments' => ['max_bytes' => '0.5']]))->attachmentMaxBytes(),
        ];
        foreach ($invalid as $call) {
            try {
                $call();
                self::fail('numeric string should be rejected');
            } catch (InvalidMailerException) {
            }
        }

        $config = MailerConfigValidator::normalize('relay', $this->smtpConfig([
            'port' => '465',
            'timeout' => '20',
        ]));
        self::assertSame(465, $config['port']);
        self::assertSame(20, $config['timeout']);
    }

    public function testExtendIsCaseInsensitiveAndStillValidatesCommonConfig(): void
    {
        $captured = null;
        $factory = new MailFactory();
        $factory->extend('SMTP', static function (array $config, string $name) use (&$captured): MailerInterface {
            $captured = $config;

            return new class implements MailerInterface {
                public function send(MailMessage $message): MailResult
                {
                    return new MailResult('stub@example.com', 'smtp');
                }

                public function transport(): string
                {
                    return 'smtp';
                }
            };
        });

        $manager = new MailerManager([
            'default_mailer' => 'relay',
            'mailers' => ['relay' => ['transport' => 'smtp', 'from' => ['address' => 'a@example.com']]],
        ], null, $factory);

        $result = $manager->send($this->baseMessage());
        self::assertSame('stub@example.com', $result->messageId);
        self::assertInstanceOf(Address::class, $captured['from']);
        self::assertSame('smtp', $captured['transport']);

        $invalidManager = new MailerManager([
            'default_mailer' => 'relay',
            'mailers' => ['relay' => ['transport' => 'smtp']],
        ], null, $factory);
        $this->expectException(InvalidMailerException::class);
        $invalidManager->send($this->baseMessage());
    }

    public function testMessageCollectionsArePrivateAndExposeLists(): void
    {
        foreach (['to', 'cc', 'bcc', 'recipientKeys', 'attachments', 'embeds', 'templateVars'] as $property) {
            self::assertTrue((new \ReflectionProperty(MailMessage::class, $property))->isPrivate(), $property);
        }

        $message = $this->baseMessage();
        self::assertInstanceOf(Address::class, $message->toList()[0]);
        self::assertSame([], $message->attachmentList());
        self::assertSame([], $message->embedList());
        self::assertSame([], $message->templateVariables());
    }

    public function testMailAndSendmailWarningsAreEmittedOnceInsideCoroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\Coroutine\run')) {
            self::markTestSkipped('Swoole coroutine runtime is not available');
        }

        $this->resetStaticProperty(BlockingTransportWarning::class, 'warned', []);
        $output = $this->captureErrorLog(static function (): void {
            \Swoole\Coroutine\run(static function (): void {
                BlockingTransportWarning::warnIfCoroutine('p2-transport');
                BlockingTransportWarning::warnIfCoroutine('p2-transport');
            });
        });

        self::assertSame(1, substr_count($output, '[p2-transport]'));
    }

    public function testDefaultMailerMustBeExplicitWithMultipleMailers(): void
    {
        $config = new MailConfig([
            'mailers' => [
                'a' => ['transport' => 'log', 'from' => ['address' => 'a@example.com']],
                'b' => ['transport' => 'log', 'from' => ['address' => 'b@example.com']],
            ],
        ]);

        $this->expectException(InvalidMailerException::class);
        $config->defaultMailer();
    }

    public function testConfiguredDefaultMailerMustExist(): void
    {
        $config = new MailConfig([
            'default_mailer' => 'missing',
            'mailers' => ['a' => ['transport' => 'log', 'from' => ['address' => 'a@example.com']]],
        ]);

        $this->expectException(InvalidMailerException::class);
        $config->defaultMailer();
    }

    public function testRecipientLimitAndStrictMaxConfig(): void
    {
        $manager = $this->logManager(['max_recipients' => 2]);
        $message = (new MailMessage())->subject('s')->text('hi')
            ->to('a@example.com')
            ->to('b@example.com')
            ->to('c@example.com');

        try {
            $manager->send($message);
            self::fail('recipient limit should throw');
        } catch (InvalidMailMessageException $e) {
            self::assertStringContainsString('limit of 2', $e->getMessage());
        }

        $result = $this->logManager(['max_recipients' => 2])->send(
            (new MailMessage())->subject('s')->text('hi')
                ->to('a@example.com')
                ->to('b@example.com')
                ->cc('a@example.com')
        );
        self::assertSame('log', $result->transport);

        foreach (['10x', '0', '10001'] as $value) {
            try {
                (new MailConfig(['max_recipients' => $value]))->maxRecipients();
                self::fail('max_recipients should be rejected: ' . $value);
            } catch (InvalidMailerException) {
            }
        }
    }

    public function testLogTransportWarnsOnceAndMasksSubject(): void
    {
        $this->resetStaticProperty(LogTransport::class, 'warned', false);
        $manager = $this->logManager();
        $message = (new MailMessage())->to('user@example.com')->subject('隐藏主题')->text('hi');

        $output = $this->captureErrorLog(static function () use ($manager, $message): void {
            $manager->send($message);
            $manager->send($message);
        });
        self::assertSame(1, substr_count($output, 'does not deliver mail'));

        $mailer = $manager->mailer('log');
        self::assertInstanceOf(LogTransport::class, $mailer);
        $sent = $mailer->sent();
        self::assertSame('隐藏***', $sent[0]['subject']);
        self::assertSame(4, $sent[0]['subject_length']);
        self::assertCount(2, $sent);
    }

    public function testTemplateReadErrorsAndPcreFailuresThrow(): void
    {
        $dir = $this->tempDir();
        $unreadable = $dir . '/unreadable.html';
        file_put_contents($unreadable, 'x');
        chmod($unreadable, 0000);
        try {
            if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
                try {
                    (new TemplateRenderer($dir))->render('unreadable', []);
                    self::fail('unreadable template should throw');
                } catch (TemplateNotFoundException) {
                }
            }
        } finally {
            chmod($unreadable, 0644);
        }

        file_put_contents($dir . '/dense.html', str_repeat('{{x}}', 1000));
        $previousJit = ini_set('pcre.jit', '0');
        $previousLimit = ini_set('pcre.backtrack_limit', '3');
        try {
            (new TemplateRenderer($dir))->render('dense', ['x' => 'y']);
            self::fail('pcre failure should throw');
        } catch (InvalidMailMessageException $e) {
            self::assertStringContainsString('could not be rendered', $e->getMessage());
        } finally {
            if ($previousJit !== false) {
                ini_set('pcre.jit', $previousJit);
            }
            if ($previousLimit !== false) {
                ini_set('pcre.backtrack_limit', $previousLimit);
            }
        }
    }

    public function testTemplateCacheRefreshesWhenFileChanges(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/welcome.html';
        file_put_contents($file, 'v1 {{ name }}');
        $renderer = new TemplateRenderer($dir);

        self::assertSame('v1 A', $renderer->render('welcome', ['name' => 'A']));
        file_put_contents($file, 'version-two {{ name }}');
        self::assertSame('version-two A', $renderer->render('welcome', ['name' => 'A']));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function smtpConfig(array $override = []): array
    {
        return array_replace_recursive([
            'transport' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'AKIAIOSFODNN7EXAMPLE',
            'password' => 'secret-key',
            'auth' => true,
            'from' => ['address' => 'noreply@example.com', 'name' => 'S'],
        ], $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function smtpMailer(PHPMailer $box, array $override = []): PhpMailerMailer
    {
        $config = MailerConfigValidator::normalize('relay', $this->smtpConfig($override));
        $compiler = new MessageCompiler(new TemplateRenderer(sys_get_temp_dir()), [], 1000);

        return new PhpMailerMailer(new SmtpTransport($config), $config, $compiler, static fn (): PHPMailer => $box);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logManager(array $extra = [], ?RecordingLogger $logger = null): MailerManager
    {
        return new MailerManager(array_replace_recursive([
            'default_mailer' => 'log',
            'attachments' => ['max_bytes' => 1000],
            'mailers' => [
                'log' => [
                    'transport' => 'log',
                    'from' => ['address' => 'log@example.com', 'name' => 'Log'],
                ],
            ],
        ], $extra), $logger);
    }

    private function baseMessage(): MailMessage
    {
        return (new MailMessage())->to('user@example.com')->subject('s')->text('hi');
    }

    private function dkimKey(): string
    {
        if (!function_exists('openssl_pkey_new')) {
            self::markTestSkipped('openssl is not available');
        }

        // 某些环境（如 Homebrew PHP）没有默认 openssl.cnf，生成密钥需要显式配置
        $config = tempnam(sys_get_temp_dir(), 'mail-ossl-');
        self::assertIsString($config);
        file_put_contents($config, "RANDFILE = {$config}.rnd\n[req]\ndistinguished_name = dn\n[dn]\n");

        $key = openssl_pkey_new([
            'config' => $config,
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            self::markTestSkipped('openssl cannot generate a test key');
        }
        self::assertTrue(openssl_pkey_export($key, $pem, null, ['config' => $config]));

        $file = tempnam(sys_get_temp_dir(), 'mail-dkim-key-');
        self::assertIsString($file);
        file_put_contents($file, $pem);
        @unlink($config);
        @unlink($config . '.rnd');

        return $file;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mail-p2-' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    private function captureErrorLog(callable $callback): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mail-err-');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);
        try {
            $callback();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $contents = file_get_contents($file);
        @unlink($file);

        return $contents === false ? '' : $contents;
    }

    private function resetStaticProperty(string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
