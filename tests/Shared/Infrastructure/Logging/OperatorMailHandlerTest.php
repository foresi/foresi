<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\OperatorMailHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\Email;

/**
 * The error mail to the operator: only through the operator's own mailer, never
 * when it is not configured, never for a client error, with the level and the
 * start of the message in the subject and no secrets in the body.
 */
final class OperatorMailHandlerTest extends TestCase
{
    public function testNothingIsHandledWithoutTheMailerOrTheAddress(): void
    {
        foreach ([['', ''], ['smtp://ops.example.com', ''], ['', 'ops@example.com']] as [$dsn, $email]) {
            $handler = new OperatorMailHandler($dsn, $email);

            self::assertFalse($handler->isHandling($this->record(Level::Critical, 'Down')), $dsn.'|'.$email);
            self::assertFalse($handler->handle($this->record(Level::Critical, 'Down')));
        }
    }

    public function testAConfiguredMailerHandlesErrorsOnly(): void
    {
        $handler = new OperatorMailHandler('null://null', 'ops@example.com');

        self::assertTrue($handler->isHandling($this->record(Level::Error, 'Failed')));
        self::assertFalse($handler->isHandling($this->record(Level::Warning, 'Slow')));
        $handler->handle($this->record(Level::Error, 'Failed'));
    }

    public function testTheMailNamesTheLevelAndTheMessageStart(): void
    {
        $transport = new RecordingTransport();
        $handler = OperatorMailHandler::through($transport, 'ops@example.com');
        $long = 'Error thrown while handling message '.str_repeat('x', 100);

        $handler->handleBatch([
            $this->record(Level::Info, 'Matched route'),
            $this->record(Level::Critical, $long, ['exception' => new \RuntimeException('SMTP of a@example.com refused')]),
            $this->record(Level::Error, 'Second'),
        ]);

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('[Foresi] CRITICAL: '.mb_substr($long, 0, 80), $email->getSubject());
        self::assertSame('ops@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('ops@example.com', $email->getFrom()[0]->getAddress());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('CRITICAL', $body);
        self::assertStringContainsString('Second', $body);
        self::assertStringNotContainsString('Matched route', $body);
        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('SMTP of [email] refused', $body);
        self::assertStringNotContainsString('a@example.com', $body);
    }

    public function testAClientErrorIsNotMailed(): void
    {
        $transport = new RecordingTransport();
        $handler = OperatorMailHandler::through($transport, 'ops@example.com');

        $handler->handle($this->record(Level::Error, 'No route', ['exception' => new NotFoundHttpException()]));

        self::assertSame([], $transport->sent);
        self::assertTrue($handler->isHandling($this->record(Level::Error, 'No route')));
    }

    public function testAFailingMailServerDoesNotBreakTheRequest(): void
    {
        $transport = new RecordingTransport(fail: true);
        $handler = OperatorMailHandler::through($transport, 'ops@example.com');

        $handler->handle($this->record(Level::Error, 'Failed'));

        self::assertSame([], $transport->sent);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(Level $level, string $message, array $context = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable('2026-11-06 10:00:00'), 'app', $level, $message, $context);
    }
}
