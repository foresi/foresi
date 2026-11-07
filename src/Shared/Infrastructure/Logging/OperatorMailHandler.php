<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\MailHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mailer\Exception\ExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Mails errors to the operator of the installation, through the operator's own
 * mailer (OPERATOR_MAILER_DSN), never a firm's server and never the message bus.
 * Without the mailer or the address it handles nothing. A client error (4xx) is
 * not the operator's to fix and is not mailed. A mail server that fails loses the
 * mail, not the request: the error is in the log file anyway.
 */
final class OperatorMailHandler extends MailHandler
{
    private const int SUBJECT_LENGTH = 80;

    private ?TransportInterface $transport = null;

    public function __construct(
        #[Autowire(env: 'OPERATOR_MAILER_DSN')]
        private readonly string $mailerDsn,
        #[Autowire(env: 'OPERATOR_EMAIL')]
        private readonly string $operatorEmail,
        #[Autowire(param: 'app.product_name')]
        private readonly string $productName = 'Foresi',
    ) {
        parent::__construct(Level::Error);
    }

    /**
     * A handler sending through the given transport, for tests.
     */
    public static function through(TransportInterface $transport, string $operatorEmail): self
    {
        $handler = new self('', $operatorEmail);
        $handler->transport = $transport;

        return $handler;
    }

    public function isHandling(LogRecord $record): bool
    {
        return '' !== $this->operatorEmail
            && ('' !== $this->mailerDsn || $this->transport instanceof TransportInterface)
            && parent::isHandling($record);
    }

    public function handleBatch(array $records): void
    {
        parent::handleBatch(array_values(array_filter($records, fn (LogRecord $record): bool => !$this->isClientError($record))));
    }

    public function handle(LogRecord $record): bool
    {
        if ($this->isClientError($record)) {
            return false;
        }

        return parent::handle($record);
    }

    protected function send(string $content, array $records): void
    {
        $highest = $this->getHighestRecord($records);
        $email = new Email()
            ->from($this->operatorEmail)
            ->to($this->operatorEmail)
            ->subject(\sprintf('[%s] %s: %s', $this->productName, $highest->level->getName(), mb_substr(RedactSecrets::scrub($highest->message), 0, self::SUBJECT_LENGTH)))
            ->text($content);

        try {
            ($this->transport ??= Transport::fromDsn($this->mailerDsn))->send($email);
        } catch (ExceptionInterface) {
            // Logging the failure would come back here; the error itself is in the log file.
        }
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new OperatorMailFormatter();
    }

    private function isClientError(LogRecord $record): bool
    {
        $exception = $record->context['exception'] ?? null;

        return $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500;
    }
}
