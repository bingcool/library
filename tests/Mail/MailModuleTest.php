<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swoolefy\Library\Mail\Config\MailConfig;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\TemplateNotFoundException;
use Swoolefy\Library\Mail\MailerManager;
use Swoolefy\Library\Mail\MailFactory;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\PhpMailerMailer;
use Swoolefy\Library\Mail\Support\HtmlToText;
use Swoolefy\Library\Mail\Support\MailerConfigValidator;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\Template\TemplateRenderer;
use Swoolefy\Library\Mail\Transport\LogTransport;
use Swoolefy\Library\Mail\Transport\MailTransport;
use Swoolefy\Library\Mail\Transport\SendmailTransport;
use Swoolefy\Library\Mail\Transport\SmtpTransport;

final class MailModuleTest extends TestCase
{
    public function testConfigStripsOuterKeyAndFallsBackToFirstMailer(): void
    {
        $config = MailConfig::fromArray([
            'mail' => [
                'mailers' => [
                    'qq' => ['transport' => 'log', 'from' => ['address' => 'a@qq.com']],
                ],
            ],
        ]);

        self::assertSame('qq', $config->defaultMailer());
        self::assertTrue($config->hasMailer('qq'));
        self::assertSame(20 * 1024 * 1024, $config->attachmentMaxBytes());
    }

    public function testEmptyMailersThrowInsteadOfFallingBackToPhpMail(): void
    {
        $this->expectException(InvalidMailerException::class);
        (new MailConfig([]))->defaultMailer();
    }

    public function testPortAndEncryptionAreDecoupled(): void
    {
        $normalized = MailerConfigValidator::normalize('qq', $this->qq(['port' => 465, 'encryption' => 'tls']));

        self::assertSame(465, $normalized['port']);
        self::assertSame('tls', $normalized['encryption']);
    }

    public function testAuthRequiresPassword(): void
    {
        $this->expectException(InvalidMailerException::class);
        $this->expectExceptionMessage('password');

        MailerConfigValidator::normalize('qq', $this->qq(['password' => '']));
    }

    public function testKnownProviderFromMustMatchUsername(): void
    {
        $this->expectException(InvalidMailerException::class);
        $this->expectExceptionMessage('from.address must match username');

        MailerConfigValidator::normalize('qq', $this->qq([
            'from' => ['address' => 'other@qq.com', 'name' => 'X'],
        ]));
    }

    public function testDingtalkRejectsPort25(): void
    {
        $this->expectException(InvalidMailerException::class);
        $this->expectExceptionMessage('DingTalk');

        MailerConfigValidator::normalize('dingtalk', [
            'transport' => 'smtp',
            'host' => 'smtp.em.dingtalk.com',
            'port' => 25,
            'encryption' => '',
            'username' => 'a@example.com',
            'password' => 'client-secret',
            'auth' => true,
            'from' => ['address' => 'a@example.com', 'name' => 'A'],
        ]);
    }

    public function testNetease126AlsoRequiresMatchingFrom(): void
    {
        $normalized = MailerConfigValidator::normalize('netease', [
            'transport' => 'smtp',
            'host' => 'smtp.126.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'a@126.com',
            'password' => 'auth-code-126',
            'from' => ['address' => 'a@126.com', 'name' => 'A'],
        ]);

        self::assertTrue($normalized['match_from']);
        self::assertSame('smtp.126.com', $normalized['host']);
    }

    public function testSendmailPathWithFlagsIsRejected(): void
    {
        $this->expectException(InvalidMailerException::class);

        MailerConfigValidator::normalize('local', [
            'transport' => 'sendmail',
            'path' => '/usr/sbin/sendmail -bs',
            'from' => ['address' => 'a@example.com', 'name' => 'A'],
        ]);
    }

    public function testMessageDedupAndRejectsLineBreaks(): void
    {
        $message = (new MailMessage())
            ->to('User@example.com', '张三')
            ->to('user@example.com')
            ->cc('user@example.com')
            ->bcc('other@example.com');

        self::assertCount(1, $message->toList());
        self::assertCount(0, $message->ccList());
        self::assertCount(1, $message->bccList());

        $this->expectException(InvalidMailMessageException::class);
        (new MailMessage())->to("user@example.com", "bad\nname");
    }

    public function testTemplateEscapesInterpolatesRawAndExtractsLink(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/welcome.html', '<p>{{ name }}</p>{{{ html }}}<a href="https://example.com/a">点这里</a>');

        $html = (new TemplateRenderer($dir))->render('welcome', [
            'name' => '<b>张</b>',
            'html' => '<i>ok</i>',
        ]);

        self::assertStringContainsString('&lt;b&gt;张&lt;/b&gt;', $html);
        self::assertStringContainsString('<i>ok</i>', $html);
        self::assertSame(
            '点这里 (https://example.com/a)',
            HtmlToText::convert('<a href="https://example.com/a">点这里</a>')
        );
    }

    public function testTemplateRejectsTraversalAndMissingFile(): void
    {
        $renderer = new TemplateRenderer($this->tempDir());

        try {
            $renderer->render('../welcome', []);
            self::fail('traversal should be rejected');
        } catch (TemplateNotFoundException $e) {
            self::assertStringNotContainsString('..', $e->getMessage());
        }

        $this->expectException(TemplateNotFoundException::class);
        $renderer->render('missing', []);
    }

    public function testLogMailerRecordsMaskedEnvelopeAndRendersTemplate(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/note.html', '你好 {{ name }}');
        $file = $dir . '/a.txt';
        file_put_contents($file, 'pdf');

        $manager = new MailerManager([
            'default_mailer' => 'log',
            'template' => ['view_path' => $dir, 'vars' => ['name' => '默认']],
            'attachments' => ['max_bytes' => 100],
            'mailers' => [
                'log' => [
                    'transport' => 'log',
                    'from' => ['address' => 'log@example.com', 'name' => 'Log'],
                ],
            ],
        ]);

        $result = $manager->send(
            (new MailMessage())
                ->to('user@example.com', '张三')
                ->cc('cc@example.com')
                ->bcc('hidden@example.com')
                ->subject('订单')
                ->viaTemplate('note', ['name' => '李四'])
                ->attach($file, 'a.txt')
                ->embed($file, 'logo')
        );

        self::assertSame('log', $result->transport);
        $mailer = $manager->mailer('log');
        self::assertInstanceOf(LogTransport::class, $mailer);
        $sent = $mailer->sent();
        self::assertSame('订单***', $sent[0]['subject']);
        self::assertSame(2, $sent[0]['subject_length']);
        self::assertSame(['u***@example.com'], $sent[0]['to']);
        self::assertSame(['c***@example.com'], $sent[0]['cc']);
        self::assertSame(1, $sent[0]['bcc_count']);
        self::assertSame(1, $sent[0]['attachments']);
        self::assertSame(1, $sent[0]['embeds']);
        self::assertStringStartsWith('log-', $result->messageId);
    }

    public function testAttachmentLimitAndMissingFile(): void
    {
        $manager = $this->logManager(8);
        $message = (new MailMessage())->to('user@example.com')->subject('s')->text('hi')->attachData('0123456789', 'a.bin');

        $this->expectException(InvalidMailMessageException::class);
        $manager->send($message);
    }

    public function testDotDotAttachmentIsRejected(): void
    {
        $manager = $this->logManager(1000);
        $message = (new MailMessage())
            ->to('user@example.com')
            ->subject('s')
            ->text('hi')
            ->attach('/tmp/../etc/passwd');

        $this->expectException(InvalidMailMessageException::class);
        $manager->send($message);
    }

    public function testMessageFromOverrideMustMatchProviderUsername(): void
    {
        $config = MailerConfigValidator::normalize('qq', $this->qq());
        $compiler = new MessageCompiler(new TemplateRenderer(sys_get_temp_dir()), [], 1000);
        $mailer = new LogTransport($config, $compiler);

        $this->expectException(InvalidMailMessageException::class);
        $mailer->send(
            (new MailMessage())
                ->to('user@example.com')
                ->subject('s')
                ->text('hi')
                ->from('other@qq.com')
        );
    }

    public function testSmtpApplyDoesNotOpenAConnection(): void
    {
        $config = MailerConfigValidator::normalize('qq', $this->qq(['timeout' => 8]));
        $phpMailer = new PHPMailer(true);
        (new SmtpTransport($config))->apply($phpMailer);

        self::assertSame('smtp', $phpMailer->Mailer);
        self::assertSame('smtp.qq.com', $phpMailer->Host);
        self::assertSame(465, $phpMailer->Port);
        self::assertSame(PHPMailer::ENCRYPTION_SMTPS, $phpMailer->SMTPSecure);
        self::assertFalse($phpMailer->SMTPAutoTLS);
        self::assertSame(0, $phpMailer->SMTPDebug);
        self::assertFalse($phpMailer->SMTPKeepAlive);
        self::assertSame(8, $phpMailer->Timeout);
    }

    public function testPlaintextSmtpDoesNotEnableAutoTls(): void
    {
        $config = MailerConfigValidator::normalize('plain', [
            'transport' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 25,
            'encryption' => '',
            'auth' => false,
            'from' => ['address' => 'a@example.com', 'name' => 'A'],
        ]);
        $phpMailer = new PHPMailer(true);
        $phpMailer->SMTPAutoTLS = true;
        (new SmtpTransport($config))->apply($phpMailer);

        self::assertSame('', $phpMailer->SMTPSecure);
        self::assertFalse($phpMailer->SMTPAutoTLS);
    }

    public function testMissingTemplateVariableAndDkimKey(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/welcome.html', '你好 {{ name }}');
        $renderer = new TemplateRenderer($dir);

        try {
            $renderer->render('welcome', []);
            self::fail('missing variable should throw');
        } catch (InvalidMailMessageException $e) {
            self::assertStringContainsString('name', $e->getMessage());
        }

        $this->expectException(InvalidMailerException::class);
        MailerConfigValidator::normalize('qq', $this->qq([
            'dkim' => ['domain' => 'qq.com', 'private' => $dir . '/missing.pem'],
        ]));
    }

    public function testTransportFailureRedactsPasswordAndStillLogsWithoutIt(): void
    {
        $password = 's3cret-pass-value';
        $config = MailerConfigValidator::normalize('qq', $this->qq(['password' => $password]));
        $compiler = new MessageCompiler(new TemplateRenderer(sys_get_temp_dir()), [], 1000);
        $mailer = new PhpMailerMailer(
            new SmtpTransport($config),
            $config,
            $compiler,
            static function () use ($password): PHPMailer {
                return new class($password) extends PHPMailer {
                    public function __construct(private string $secret)
                    {
                        parent::__construct(true);
                    }

                    public function send(): bool
                    {
                        throw new \PHPMailer\PHPMailer\Exception('535 denied for ' . $this->secret);
                    }
                };
            }
        );

        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $factory = new MailFactory();
        $factory->extend('smtp', static function () use ($mailer): PhpMailerMailer {
            return $mailer;
        });
        $manager = new MailerManager([
            'default_mailer' => 'qq',
            'mailers' => ['qq' => $this->qq(['password' => $password])],
        ], $logger, $factory);

        try {
            $manager->send((new MailMessage())->to('user@example.com')->subject('s')->text('hi'));
            self::fail('transport failure should throw');
        } catch (MailTransportException $e) {
            self::assertStringNotContainsString($password, $e->getMessage());
            self::assertStringContainsString('***', $e->getMessage());
            self::assertNull($e->getPrevious());
        }

        $encoded = json_encode($logger->records, JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        self::assertStringNotContainsString($password, $encoded);
        self::assertSame('u***@example.com', $logger->records[0]['context']['to'][0]);
        self::assertSame(MailTransportException::class, $logger->records[0]['context']['exception']);
    }

    public function testSuccessfulPhpMailerPathSetsUtf8AndDoesNotKeepAlive(): void
    {
        $config = MailerConfigValidator::normalize('qq', $this->qq());
        $compiler = new MessageCompiler(new TemplateRenderer(sys_get_temp_dir()), [], 1000);
        $box = new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                $this->MessageID = '<stub@example.com>';

                return true;
            }
        };
        $mailer = new PhpMailerMailer(new SmtpTransport($config), $config, $compiler, static fn (): PHPMailer => $box);
        $result = $mailer->send((new MailMessage())->to('user@example.com')->subject('主题')->text('你好'));

        self::assertSame('<stub@example.com>', $result->messageId);
        self::assertSame(PHPMailer::CHARSET_UTF8, $box->CharSet);
        self::assertSame(0, $box->SMTPDebug);
        self::assertFalse($box->SMTPKeepAlive);
        self::assertSame('主题', $box->Subject);
    }

    public function testSendmailAndMailApplyWithoutSending(): void
    {
        $binary = is_file('/usr/sbin/sendmail') ? '/usr/sbin/sendmail' : PHP_BINARY;
        $sendmail = MailerConfigValidator::normalize('local', [
            'transport' => 'sendmail',
            'path' => $binary,
            'from' => ['address' => 'a@example.com', 'name' => 'A'],
        ]);
        $phpMailer = new PHPMailer(true);
        (new SendmailTransport($sendmail))->apply($phpMailer);
        self::assertSame('sendmail', $phpMailer->Mailer);
        self::assertSame($binary, $phpMailer->Sendmail);

        $mail = MailerConfigValidator::normalize('php', [
            'transport' => 'mail',
            'from' => ['address' => 'a@example.com', 'name' => 'A'],
        ]);
        (new MailTransport($mail))->apply($phpMailer);
        self::assertSame('mail', $phpMailer->Mailer);
    }

    public function testUnknownTransportIsNotRetryableConfigError(): void
    {
        $this->expectException(InvalidMailerException::class);
        (new MailFactory())->make('x', [
            'transport' => 'pigeon',
            'from' => ['address' => 'a@example.com'],
        ], new MailConfig([]));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function qq(array $override = []): array
    {
        return array_replace_recursive([
            'transport' => 'smtp',
            'host' => 'smtp.qq.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'a@qq.com',
            'password' => 'qq-auth-code-16',
            'timeout' => 10,
            'auth' => true,
            'from' => ['address' => 'a@qq.com', 'name' => 'A'],
        ], $override);
    }

    private function logManager(int $maxBytes): MailerManager
    {
        return new MailerManager([
            'default_mailer' => 'log',
            'attachments' => ['max_bytes' => $maxBytes],
            'mailers' => [
                'log' => [
                    'transport' => 'log',
                    'from' => ['address' => 'log@example.com', 'name' => 'Log'],
                ],
            ],
        ]);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mail-test-' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}
