<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailServers;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendRefused;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\TotalRounding;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An invoice of a firm with a mail server, ready to be sent, and the worker of
 * the mail queue.
 */
abstract class SendingTestCase extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    protected InvoiceSends $sends;
    protected LinkTokens $tokens;
    protected Connection $connection;
    protected RecordingMailGateway $gateway;
    protected InMemoryTransport $mailQueue;
    protected RunMessages $worker;
    protected TenantId $tenant;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        parent::setUp();
        RecordingMailGateway::reset();
        $container = self::getContainer();
        $this->sends = $container->get(InvoiceSends::class);
        $this->tokens = $container->get(LinkTokens::class);
        $this->connection = $container->get(Connection::class);
        $this->gateway = new RecordingMailGateway();
        $queue = $container->get('messenger.transport.mail');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $this->mailQueue = $queue;
        $this->worker = new RunMessages($queue, $container->get(MessageBusInterface::class));
        $this->tenant = $container->get(TenantContext::class)->current();
        $this->em->persist(new Tenant($this->tenant, 'Example Company', new \DateTimeImmutable('2026-10-01')));
        $this->em->flush();
        $this->serverFor();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    protected function serverFor(): void
    {
        $secrets = self::getContainer()->get(MailSecrets::class);
        self::getContainer()->get(MailServers::class)->save(FirmMailServer::define($this->firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $secrets->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        $this->em->flush();
    }

    protected function contactEmail(string $address): void
    {
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $firm = $settings->for($this->firm);
        $firm->change(TotalRounding::None, '', '', '', $address, '', '');
        $settings->save($firm);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $overrides named arguments of SendRequest
     */
    protected function request(Invoice|string $invoice, array $overrides = []): SendRequest
    {
        return new \ReflectionClass(SendRequest::class)->newInstanceArgs(array_merge([
            'invoiceId' => $invoice instanceof Invoice ? $invoice->id() : $invoice,
            'to' => ['billing@example.com'],
            'cc' => [],
            'copyToMe' => false,
            'subject' => 'Invoice {number}',
            'body' => "Dear customer,\nplease find invoice {number} for {amount_total}.\n\n{link}",
        ], $overrides));
    }

    /**
     * Fetched when first used, so a test may replace what it depends on before.
     */
    protected function sending(): SendInvoice
    {
        return self::getContainer()->get(SendInvoice::class);
    }

    /**
     * @param array<string, mixed> $overrides named arguments of SendRequest
     */
    protected function sent(Invoice $invoice, array $overrides = []): InvoiceSend
    {
        return $this->sending()->send($this->request($invoice, $overrides));
    }

    protected function refusalOfSend(callable $send): string
    {
        try {
            $send();
        } catch (SendRefused $refused) {
            return $refused->reason;
        }
        self::fail('Expected the send to be refused.');
    }

    /**
     * The stored row of a send, read again on every call.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function row(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM invoice_send WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function invoiceRow(Invoice $invoice): array
    {
        $row = $this->connection->fetchAssociative('SELECT version, delivered_at, deleted_at FROM invoice WHERE id = ?', [$invoice->id()]);
        self::assertIsArray($row);

        return $row;
    }

    protected function sendCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM invoice_send');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    protected function sentDocumentsDir(): string
    {
        return self::getContainer()->getParameter('app.sent_documents_dir');
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function text(array $row, string $column): string
    {
        $value = $row[$column];
        self::assertIsString($value, $column);

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function integer(array $row, string $column): int
    {
        $value = $row[$column];
        self::assertIsNumeric($value, $column);

        return (int) $value;
    }

    /**
     * A boolean column, which SQLite gives as 0 or 1 and PostgreSQL as false or true.
     *
     * @param array<string, mixed> $row
     */
    protected static function flag(array $row, string $column): bool
    {
        $value = $row[$column];
        if (\is_bool($value)) {
            return $value;
        }
        self::assertIsNumeric($value, $column);

        return 1 === (int) $value;
    }

    /**
     * A stored moment to the second, as the databases write it differently after that.
     *
     * @param array<string, mixed> $row
     */
    protected static function moment(array $row, string $column): string
    {
        return substr(self::text($row, $column), 0, 19);
    }

    protected function linkOf(string $sendId, \App\Invoicing\Domain\LinkAudience $audience = \App\Invoicing\Domain\LinkAudience::Customer): string
    {
        return 'http://localhost/d/'.$this->tokens->token($sendId, $audience);
    }
}
