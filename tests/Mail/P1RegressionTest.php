<?php

declare(strict_types=1);

namespace Swoolefy\Library\Tests\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swoolefy\Library\Mail\Contracts\MailerInterface;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\PartialDeliveryException;
use Swoolefy\Library\Mail\MailerManager;
use Swoolefy\Library\Mail\MailFactory;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\PhpMailerMailer;
use Swoolefy\Library\Mail\Support\MailerConfigValidator;
use Swoolefy\Library\Mail\Support\MessageCompiler;
use Swoolefy\Library\Mail\Template\TemplateRenderer;
use Swoolefy\Library\Mail\Transport\SmtpTransport;
use Swoolefy\Library\Mail\ValueObject\MailResult;

final class P1RegressionTest extends TestCase
{
    public function testPartialDeliveryThrowsNonRetryableException(): void
    {
        $mailer = $this->smtpMailer(new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                $this->fire(true, 'good@example.com');
                $this->fire(false, 'bad@example.com');
                throw new \PHPMailer\PHPMailer\Exception(
                    'SMTP Error: The following recipients failed: bad@example.com: 550',
                    PHPMailer::STOP_CONTINUE
                );
            }

            private function fire(bool $sent, string $to): void
            {
                if (is_callable($this->action_function)) {
                    call_user_func($this->action_function, $sent, [[$to, '']], [], [], 's', 'body', 'from@example.com', []);
                }
            }
        });

        try {
            $mailer->send($this->message());
            self::fail('partial delivery should throw');
        } catch (PartialDeliveryException $e) {
            self::assertSame(1, $e->acceptedRecipients);
            self::assertStringContainsString('bad@example.com', $e->getMessage());
            self::assertNotInstanceOf(MailTransportException::class, $e);
        }
    }

    public function testAllRecipientsRejectedRemainsRetryableTransportError(): void
    {
        $mailer = $this->smtpMailer(new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                if (is_callable($this->action_function)) {
                    call_user_func($this->action_function, false, [['bad@example.com', '']], [], [], 's', 'body', 'from@example.com', []);
                }
                throw new \PHPMailer\PHPMailer\Exception(
                    'SMTP Error: The following recipients failed: bad@example.com: 550',
                    PHPMailer::STOP_CONTINUE
                );
            }
        });

        $this->expectException(MailTransportException::class);
        $mailer->send($this->message());
    }

    public function testConnectionFailureBeforeDataRemainsRetryable(): void
    {
        $mailer = $this->smtpMailer(new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                throw new \PHPMailer\PHPMailer\Exception('SMTP connect() failed', PHPMailer::STOP_CRITICAL);
            }
        });

        $this->expectException(MailTransportException::class);
        $mailer->send($this->message());
    }

    public function testAttachDataRejectsMimeHeaderInjection(): void
    {
        $this->expectException(InvalidMailMessageException::class);

        (new MailMessage())->attachData('data', 'a.txt', "text/plain\r\nX-Injected: yes");
    }

    public function testAttachDataRejectsMalformedMimeTypes(): void
    {
        $malformed = [
            'text/plain; charset=utf-8',
            "text/plain\0",
            'text/plain, text/html',
            str_repeat('a', 128) . '/plain',
        ];

        foreach ($malformed as $mime) {
            try {
                (new MailMessage())->attachData('data', 'a.txt', $mime);
                self::fail('mime should be rejected: ' . $mime);
            } catch (InvalidMailMessageException) {
            }
        }

        self::assertTrue(true);
    }

    public function testValidMimeTypeIsAccepted(): void
    {
        $result = $this->logManager()->send(
            (new MailMessage())
                ->to('user@example.com')
                ->subject('s')
                ->text('hi')
                ->attachData('data', 'a.txt', 'text/plain')
        );

        self::assertSame('log', $result->transport);
    }

    public function testLoggerFailureDoesNotChangeSuccessfulSend(): void
    {
        $manager = $this->logManager($this->throwingLogger());
        $result = $manager->send((new MailMessage())->to('user@example.com')->subject('s')->text('hi'));

        self::assertSame('log', $result->transport);
    }

    public function testLoggerFailureDoesNotMaskTransportException(): void
    {
        $factory = new MailFactory();
        $factory->extend('log', static fn (): MailerInterface => new class implements MailerInterface {
            public function send(MailMessage $message): MailResult
            {
                throw new MailTransportException('transport down');
            }

            public function transport(): string
            {
                return 'log';
            }
        });

        $manager = new MailerManager([
            'default_mailer' => 'log',
            'mailers' => ['log' => ['transport' => 'log', 'from' => ['address' => 'a@example.com']]],
        ], $this->throwingLogger(), $factory);

        try {
            $manager->send((new MailMessage())->to('user@example.com')->subject('s')->text('hi'));
            self::fail('transport failure should throw');
        } catch (MailTransportException $e) {
            self::assertSame('transport down', $e->getMessage());
        }
    }

    public function testPartialDeliveryIsLoggedWithAcceptedCount(): void
    {
        $factory = new MailFactory();
        $factory->extend('log', static fn (): MailerInterface => new class implements MailerInterface {
            public function send(MailMessage $message): MailResult
            {
                throw new PartialDeliveryException('partial', 2);
            }

            public function transport(): string
            {
                return 'log';
            }
        });

        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log($level, $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $manager = new MailerManager([
            'default_mailer' => 'log',
            'mailers' => ['log' => ['transport' => 'log', 'from' => ['address' => 'a@example.com']]],
        ], $logger, $factory);

        try {
            $manager->send((new MailMessage())->to('user@example.com')->subject('s')->text('hi'));
            self::fail('partial delivery should throw');
        } catch (PartialDeliveryException) {
        }

        self::assertSame(PartialDeliveryException::class, $logger->contexts[0]['exception'] ?? null);
        self::assertSame(2, $logger->contexts[0]['accepted_recipients'] ?? null);
    }

    public function testSmtpUsernameDoesNotNeedToBeEmail(): void
    {
        $config = MailerConfigValidator::normalize('ses', [
            'transport' => 'smtp',
            'host' => 'email-smtp.us-east-1.amazonaws.com',
            'port' => 465,
            'encryption' => 'ssl',
            'auth' => true,
            'username' => 'AKIAIOSFODNN7EXAMPLE',
            'password' => 'secret',
            'from' => ['address' => 'noreply@example.com'],
        ]);

        self::assertSame('AKIAIOSFODNN7EXAMPLE', $config['username']);
    }

    public function testSmtpUsernameRejectsControlCharacters(): void
    {
        $this->expectException(InvalidMailerException::class);

        MailerConfigValidator::normalize('relay', [
            'transport' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'auth' => true,
            'username' => "user\nname",
            'password' => 'secret',
            'from' => ['address' => 'noreply@example.com'],
        ]);
    }

    private function smtpMailer(PHPMailer $box): PhpMailerMailer
    {
        $config = MailerConfigValidator::normalize('ses', [
            'transport' => 'smtp',
            'host' => 'email-smtp.us-east-1.amazonaws.com',
            'port' => 465,
            'encryption' => 'ssl',
            'auth' => true,
            'username' => 'AKIAIOSFODNN7EXAMPLE',
            'password' => 'secret',
            'from' => ['address' => 'noreply@example.com'],
        ]);
        $compiler = new MessageCompiler(new TemplateRenderer(sys_get_temp_dir()), [], 1000);

        return new PhpMailerMailer(new SmtpTransport($config), $config, $compiler, static fn (): PHPMailer => $box);
    }

    private function message(): MailMessage
    {
        return (new MailMessage())
            ->to('good@example.com')
            ->to('bad@example.com')
            ->subject('s')
            ->text('hi');
    }

    private function logManager(?AbstractLogger $logger = null): MailerManager
    {
        return new MailerManager([
            'default_mailer' => 'log',
            'attachments' => ['max_bytes' => 1000],
            'mailers' => [
                'log' => [
                    'transport' => 'log',
                    'from' => ['address' => 'log@example.com'],
                ],
            ],
        ], $logger);
    }

    private function throwingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('logger backend down');
            }
        };
    }
}
