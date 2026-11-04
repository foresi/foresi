<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\DocumentLinks;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RenderedPdf;
use App\Invoicing\Application\SendRefused;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Infrastructure\Mail\RateLimitedSends;
use App\Invoicing\Infrastructure\Mail\RouterDocumentLinks;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Infrastructure\Schedule\ScheduleTick;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SendInvoiceTest extends SendingTestCase
{
    public function testSendingStoresPdfTextAndRecipientsAndQueuesOneMessage(): void
    {
        $invoice = $this->issue->issue($this->input());

        $send = $this->sent($invoice, ['cc' => ['accounts@example.com'], 'subject' => 'Invoice 20260001', 'body' => "Invoice 20260001, due 15. 11. 2026.\n{link}"]);
        $this->em->clear();

        $row = $this->row($send->id());
        self::assertSame('queued', $row['status']);
        self::assertSame(['billing@example.com'], json_decode(self::text($row, 'recipients_to'), true));
        self::assertSame(['accounts@example.com'], json_decode(self::text($row, 'recipients_cc'), true));
        self::assertNull($row['copy_to']);
        self::assertSame('Invoice 20260001', $row['subject']);
        // As the dialog showed it, with the link left to the worker.
        self::assertSame("Invoice 20260001, due 15. 11. 2026.\n{link}", $row['body']);
        self::assertSame('cs', $row['language']);
        self::assertSame(1, self::integer($row, 'invoice_version'));
        self::assertSame(0, self::integer($row, 'attempts'));
        self::assertSame('20260001', $row['document_number']);
        self::assertSame('Example Firm s.r.o.', $row['issuer_name']);
        self::assertSame('Example Customer a.s.', $row['customer_name']);
        self::assertSame(121000, self::integer($row, 'amount_minor'));
        self::assertSame('CZK', $row['currency']);
        self::assertSame('2026-11-15', substr(self::text($row, 'due_date'), 0, 10));
        self::assertSame($this->tenant->toString().'/'.$send->id().'.pdf', $row['pdf_key']);

        $file = $this->sentDocumentsDir().'/'.self::text($row, 'pdf_key');
        self::assertFileExists($file);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($file));
        self::assertSame($row['pdf_sha256'], hash_file('sha256', $file));
        self::assertSame(0o600, fileperms($file) & 0o777);

        $queued = [...$this->mailQueue->getSent()];
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(DeliverInvoiceSend::class, $message);
        self::assertSame($send->id(), $message->sendId);
    }

    public function testTheRowHoldsNoPlainToken(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->contactEmail('office@example.com');
        $send = $this->sent($invoice, ['copyToMe' => true]);

        $row = $this->row($send->id());
        $customer = $this->tokens->token($send->id(), LinkAudience::Customer);
        $copy = $this->tokens->token($send->id(), LinkAudience::Copy);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $customer);
        self::assertNotSame($customer, $copy);
        self::assertSame(hash('sha256', $customer), $row['token_hash']);
        self::assertSame(hash('sha256', $copy), $row['copy_token_hash']);
        $stored = json_encode($row, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($customer, $stored);
        self::assertStringNotContainsString($copy, $stored);
        $envelope = serialize([...$this->mailQueue->getSent()]);
        self::assertStringNotContainsString($customer, $envelope);
        self::assertStringNotContainsString($copy, $envelope);
    }

    public function testATrashedInvoiceIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');

        self::assertSame(SendRefused::TRASHED, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        $this->assertNothingStoredOrQueued();
    }

    public function testNoServerIsRefused(): void
    {
        $this->connection->executeStatement('DELETE FROM firm_mail_server');
        $invoice = $this->issue->issue($this->input());

        self::assertSame(SendRefused::NO_SERVER, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        $this->assertNothingStoredOrQueued();
    }

    public function testNoRecipientIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        self::assertSame(SendRefused::NO_RECIPIENT, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['to' => []])));
        self::assertSame(SendRefused::NO_RECIPIENT, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['to' => ['  '], 'cc' => ['accounts@example.com']])));
        self::assertSame(SendRefused::BAD_ADDRESS, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['to' => ['not an address']])));
        $this->assertNothingStoredOrQueued();
    }

    public function testMoreThanFifteenRecipientsAreRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $addresses = array_map(static fn (int $n): string => \sprintf('person%02d@example.com', $n), range(1, 16));

        self::assertSame(SendRefused::TOO_MANY_RECIPIENTS, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['to' => \array_slice($addresses, 0, 10), 'cc' => \array_slice($addresses, 10)])));
        $this->assertNothingStoredOrQueued();

        $this->sent($invoice, ['to' => \array_slice($addresses, 0, 10), 'cc' => \array_slice($addresses, 10, 5)]);
        self::assertSame(1, $this->sendCount());
    }

    public function testALineBreakInAnAddressOrSubjectIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        self::assertSame(SendRefused::BAD_ADDRESS, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['to' => ["billing@example.com\r\nBcc: other@example.com"]])));
        self::assertSame(SendRefused::BAD_ADDRESS, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['cc' => ["accounts@example.com\nBcc: other@example.com"]])));
        self::assertSame(SendRefused::BAD_ADDRESS, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['to' => ["billing@example.com\x00"]])));
        self::assertSame(SendRefused::BAD_SUBJECT, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['subject' => "Invoice\r\nBcc: other@example.com"])));
        self::assertSame(SendRefused::BAD_SUBJECT, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['subject' => '   '])));
        self::assertSame(SendRefused::BAD_SUBJECT, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['subject' => 'Your link {link}'])));
        $this->assertNothingStoredOrQueued();
    }

    /**
     * The dialog sends its text filled in: a brace there is text, only {link} is a token.
     */
    public function testBracesOtherThanTheLinkAreSentAsTyped(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['description' => 'Ref {number}']));

        $send = $this->sent($invoice, ['subject' => 'Projekt {Alfa}: Ref {number}', 'body' => "Ref {number}, {amount}\n{link}"]);

        $row = $this->row($send->id());
        self::assertSame('Projekt {Alfa}: Ref {number}', $row['subject']);
        self::assertSame("Ref {number}, {amount}\n{link}", $row['body']);
    }

    public function testAnUndrawableDocumentIsRefusedBeforeAnythingIsStored(): void
    {
        // A table that cannot fit the page at any size of type.
        $invoice = $this->issue->issue($this->input([new LineInput('Work', Quantity::fromString('1'), str_repeat('m', 32), Money::of('123456789012.34', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('33.33'))]));

        self::assertSame(SendRefused::UNDRAWABLE, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        $this->assertNothingStoredOrQueued();
        self::assertSame([], glob($this->sentDocumentsDir().'/'.$this->tenant->toString().'/*'));
    }

    public function testALocalhostBaseIsRefusedOutsideTests(): void
    {
        $invoice = $this->issue->issue($this->input());
        $container = self::getContainer();
        // As outside the tests, where a loopback base is not allowed.
        $container->set(DocumentLinks::class, new RouterDocumentLinks($container->get(UrlGeneratorInterface::class), 'http://localhost', false));

        self::assertSame(SendRefused::LOCAL_LINK_BASE, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        $this->assertNothingStoredOrQueued();

        foreach (['http://127.0.0.1:8000', 'http://[::1]', 'https://foresi.localhost', 'http://'] as $base) {
            self::assertFalse(new RouterDocumentLinks($container->get(UrlGeneratorInterface::class), $base, false)->allowed(), $base);
        }
        self::assertTrue(new RouterDocumentLinks($container->get(UrlGeneratorInterface::class), 'https://invoices.example.com', false)->allowed());
        self::assertTrue(new RouterDocumentLinks($container->get(UrlGeneratorInterface::class), 'http://localhost', true)->allowed());
    }

    public function testAPlainHttpBaseIsRefusedInProduction(): void
    {
        $invoice = $this->issue->issue($this->input());
        $container = self::getContainer();
        $urls = $container->get(UrlGeneratorInterface::class);
        $container->set(DocumentLinks::class, new RouterDocumentLinks($urls, 'http://invoices.example.com', false, 'prod'));

        self::assertSame(SendRefused::INSECURE_LINK_BASE, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        $this->assertNothingStoredOrQueued();

        self::assertTrue(new RouterDocumentLinks($urls, 'https://invoices.example.com', false, 'prod')->secure());
        self::assertTrue(new RouterDocumentLinks($urls, 'http://invoices.example.com', false, 'dev')->secure(), 'elsewhere it only warns');
    }

    public function testMailLinksUseTheConfiguredBase(): void
    {
        $links = new RouterDocumentLinks(self::getContainer()->get(UrlGeneratorInterface::class), 'https://invoices.example.com/app', false);

        self::assertSame('https://invoices.example.com/app/d/'.str_repeat('a', 43), $links->url(str_repeat('a', 43)));
    }

    public function testSendsAreLimitedPerUser(): void
    {
        $invoice = $this->issue->issue($this->input());
        // A person's budget: the system's sends spend only the firm's (RateLimitedSendsTest).
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser('019a0000-0000-7000-8000-00000000002a', 'staff@example.com', 'hash'), 'main', ['ROLE_USER']));
        for ($i = 0; $i < 30; ++$i) {
            $this->sent($invoice);
        }
        self::assertSame(SendRefused::RATE_LIMITED, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        self::assertSame(30, $this->sendCount());
    }

    public function testSendsAreLimitedPerFirm(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);
        self::getContainer()->get('test.invoice_send_firm_limiter')->create($this->firm->id())->consume(499);

        self::assertSame(SendRefused::RATE_LIMITED, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        self::assertSame(1, $this->sendCount());
    }

    public function testASentInvoiceLocksSeriesAndNumberAndRefusesDelete(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);
        $this->worker->run();

        self::assertNotNull($this->invoiceRow($invoice)['delivered_at']);
        $this->assertRefusedFor('invoice.problem.number_locked', fn () => $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260050'])));
        $this->expectException(\DomainException::class);
        $this->trash->trash($invoice->id(), 'Wrong customer');
    }

    public function testAQueuedSendLocksSeriesAndNumber(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);

        $this->assertRefusedFor('invoice.problem.number_locked_sending', fn () => $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260050'])));
        self::assertSame('20260001', $this->reloaded($invoice)->number());
        // Anything else may change.
        $this->edited($invoice, $this->input(overrides: ['description' => 'Changed while queued']));
        self::assertSame('Changed while queued', $this->reloaded($invoice)->description());
    }

    public function testASendThatWentOutDuringTrashLocksTheNumberAfterRestore(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        RecordingMailGateway::whileSending(function () use ($invoice): void {
            $this->trash->trash($invoice->id(), 'Wrong customer');
        });
        $this->worker->run();
        $this->em->clear();
        self::assertSame('sent', $this->row($send->id())['status']);
        self::assertNull($this->invoiceRow($invoice)['delivered_at']);

        $this->trash->restore($invoice->id());
        $this->em->clear();

        // The customer has this number already.
        $this->assertRefusedFor('invoice.problem.number_locked_sent', fn () => $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260050'])));
        self::assertSame('20260001', $this->reloaded($invoice)->number());
    }

    public function testAFailedSendLocksNothingAndAllowsDelete(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['to' => [RecordingMailGateway::REFUSING_RECIPIENT]]);
        $this->runWorkerExpectingFailure();
        self::assertSame('failed', $this->row($send->id())['status']);

        $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260050']));
        self::assertSame('20260050', $this->reloaded($invoice)->number());
        $this->trash->trash($invoice->id(), 'Wrong customer');
        self::assertNotNull($this->invoiceRow($invoice)['deleted_at']);
    }

    public function testContentMayStillBeEditedAfterSending(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->worker->run();
        $before = $this->row($send->id());

        $this->edited($invoice, $this->input(overrides: ['description' => 'Changed after sending']));

        self::assertSame('Changed after sending', $this->reloaded($invoice)->description());
        $after = $this->row($send->id());
        self::assertSame($before['pdf_sha256'], $after['pdf_sha256']);
        self::assertSame($before['pdf_sha256'], hash_file('sha256', $this->sentDocumentsDir().'/'.self::text($after, 'pdf_key')));
        self::assertSame(1, self::integer($after, 'invoice_version'));
    }

    public function testTrashCancelsQueuedSendsAndRevokesEveryLink(): void
    {
        $invoice = $this->issue->issue($this->input());
        $failed = $this->sent($invoice, ['to' => [RecordingMailGateway::REFUSING_RECIPIENT]]);
        $this->runWorkerExpectingFailure();
        $queued = $this->sent($invoice);
        $this->mailQueue->reset();

        self::mockTime('2026-11-03 11:00:00');
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $this->em->clear();

        $failedRow = $this->row($failed->id());
        self::assertSame('failed', $failedRow['status']);
        self::assertSame('2026-11-03 11:00:00', self::moment($failedRow, 'revoked_at'));
        $queuedRow = $this->row($queued->id());
        self::assertSame('cancelled', $queuedRow['status']);
        self::assertSame('2026-11-03 11:00:00', self::moment($queuedRow, 'cancelled_at'));
        self::assertSame('2026-11-03 11:00:00', self::moment($queuedRow, 'revoked_at'));
    }

    public function testRestoreRevivesNoLink(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->trash->restore($invoice->id());
        $this->em->clear();

        $row = $this->row($send->id());
        self::assertSame('cancelled', $row['status']);
        self::assertNotNull($row['revoked_at']);
        self::assertFalse($this->sends->hasQueued($this->reloaded($invoice)));
    }

    public function testSendingAgainMakesANewRowAndLink(): void
    {
        $invoice = $this->issue->issue($this->input());
        $first = $this->sent($invoice);
        $this->worker->run();

        $second = $this->sent($invoice);
        $this->worker->run();
        $this->em->clear();

        self::assertNotSame($first->id(), $second->id());
        self::assertNotSame($this->row($first->id())['token_hash'], $this->row($second->id())['token_hash']);
        self::assertSame(['sent', 'sent'], [$this->row($first->id())['status'], $this->row($second->id())['status']]);
        self::assertCount(2, $this->sends->ofInvoice($this->reloaded($invoice)));
        $messages = $this->gateway->messages();
        self::assertCount(2, $messages);
        self::assertStringContainsString($this->linkOf($second->id()), $messages[1]->text);
        // The second send froze the invoice as it was then: delivered by the first.
        self::assertSame(2, self::integer($this->row($second->id()), 'invoice_version'));
    }

    public function testAnotherTenantsSendIsNotFound(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $context = self::getContainer()->get(TenantContext::class);
        $context->switchTo(\App\Tenancy\Domain\TenantId::generate());
        $this->em->clear();

        try {
            $this->sends->lock($send->id());
            self::fail('Another tenant cannot take the row.');
        } catch (\App\Invoicing\Application\UnknownInvoiceSend) {
        }
        $this->expectException(\App\Invoicing\Application\UnknownInvoice::class);
        $this->sent($invoice);
    }

    public function testStuckQueuedRowsAreDispatchedAgainOncePerPeriod(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        // As if the process died between the commit and the dispatch.
        $this->mailQueue->reset();
        $context = self::getContainer()->get(TenantContext::class);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $jobs = new RunMessages($async, self::getContainer()->get(MessageBusInterface::class));
        $tick = function () use ($context, $jobs): void {
            $context->reset();
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new ScheduleTick());
            $jobs->run();
            $context->switchTo($this->tenant);
        };

        self::mockTime('2026-11-03 10:14:00');
        $tick();
        self::assertSame([], [...$this->mailQueue->getSent()], 'not stuck yet');

        self::mockTime('2026-11-03 10:16:00');
        $tick();
        $tick();
        $queued = [...$this->mailQueue->getSent()];
        self::assertCount(1, $queued, 'once per period');
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(DeliverInvoiceSend::class, $message);
        self::assertSame($send->id(), $message->sendId);

        $this->worker->run();
        self::assertSame('sent', $this->row($send->id())['status']);
        self::mockTime('2026-11-03 10:40:00');
        $tick();
        self::assertSame([], $this->worker->pending(), 'a sent row is not stuck');
    }

    private function runWorkerExpectingFailure(): void
    {
        try {
            $this->worker->run();
            self::fail('The delivery was expected to fail.');
        } catch (\Throwable) {
        }
    }

    public function testATrashedInvoiceIsRefusedBeforeAnythingElseIsAsked(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $this->connection->executeStatement('DELETE FROM firm_mail_server');

        self::assertSame(SendRefused::TRASHED, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
    }

    public function testAnInvoiceThatKeepsChangingIsDrawnThreeTimesOnOneLimit(): void
    {
        $invoice = $this->issue->issue($this->input());
        // One send left for the firm, here a system's send without a user, which stops at its share
        // of the day: a round drawn again must not take another.
        self::getContainer()->get('test.invoice_send_firm_limiter')->create($this->firm->id())->consume(intdiv(500 * RateLimitedSends::SYSTEM_SHARE, 100) - 1);
        $pdf = new class($this->connection, $invoice->id()) implements InvoicePdf {
            public int $drawn = 0;

            public function __construct(private readonly Connection $connection, private readonly string $invoiceId)
            {
            }

            public function render(IssuedDocument $document): RenderedPdf
            {
                ++$this->drawn;
                // Someone saves the invoice while it is drawn.
                $this->connection->executeStatement('UPDATE invoice SET version = version + 1 WHERE id = ?', [$this->invoiceId]);

                return new RenderedPdf('%PDF-1.7', $document->number.'.pdf');
            }
        };
        self::getContainer()->set(InvoicePdf::class, $pdf);

        try {
            $this->sent($invoice);
            self::fail('Expected the send to give up.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The invoice kept changing while it was drawn for sending.', $failure->getMessage());
        }
        self::assertSame(3, $pdf->drawn);
        $this->assertNothingStoredOrQueued();
    }

    public function testAnIssuerCountryWithoutSupportIsUndrawable(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->connection->executeStatement("UPDATE invoice SET issuer_address_country_code = 'XX' WHERE id = ?", [$invoice->id()]);
        $this->em->clear();

        self::assertSame(SendRefused::UNDRAWABLE, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice)));
        $this->assertNothingStoredOrQueued();
    }

    public function testTheSubjectIsStoredAsOneLineWithoutSpacesAround(): void
    {
        $invoice = $this->issue->issue($this->input());

        $send = $this->sent($invoice, ['subject' => '  Invoice 20260001  ']);

        self::assertSame('Invoice 20260001', $this->row($send->id())['subject']);
    }

    public function testTheCopyGoesToTheFirmsAddressWithoutSpaces(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->contactEmail(' office@example.com ');

        $send = $this->sent($invoice, ['copyToMe' => true]);

        self::assertSame('office@example.com', $this->row($send->id())['copy_to']);
    }

    public function testWithoutAFirmAddressOrAUserThereIsNoCopy(): void
    {
        $invoice = $this->issue->issue($this->input());

        $send = $this->sent($invoice, ['copyToMe' => true]);

        self::assertNull($this->row($send->id())['copy_to']);
    }

    public function testAnAddressRepeatedInAnyCaseIsSentToOnce(): void
    {
        $invoice = $this->issue->issue($this->input());

        $send = $this->sent($invoice, [
            'to' => ['ÚČTY@example.com', 'účty@example.com', '  ', 'ÚČTY@example.com', 'billing@example.com'],
            'cc' => ['účty@example.com', 'Accounts@example.com', 'accounts@example.com'],
        ]);

        self::assertSame(['ÚČTY@example.com', 'billing@example.com'], $send->to());
        self::assertSame(['Accounts@example.com'], $send->cc());
    }

    public function testABadAddressIsNamedInTheRefusal(): void
    {
        $invoice = $this->issue->issue($this->input());

        try {
            $this->sent($invoice, ['to' => ['billing@example.com', 'not an address']]);
            self::fail('Expected the send to be refused.');
        } catch (SendRefused $refused) {
            self::assertSame(SendRefused::BAD_ADDRESS, $refused->reason);
            self::assertSame(['not an address'], $refused->details);
        }
    }

    public function testTheTextLimitsCountCharactersNotBytes(): void
    {
        $invoice = $this->issue->issue($this->input());

        $send = $this->sent($invoice, ['subject' => str_repeat('č', InvoiceSend::MAX_SUBJECT), 'body' => str_repeat('č', InvoiceSend::MAX_BODY)]);

        self::assertSame(InvoiceSend::MAX_SUBJECT, mb_strlen($send->subject()));
        self::assertSame(SendRefused::BAD_SUBJECT, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['subject' => str_repeat('č', InvoiceSend::MAX_SUBJECT + 1)])));
        self::assertSame(SendRefused::BAD_BODY, $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['body' => str_repeat('č', InvoiceSend::MAX_BODY + 1)])));
    }

    public function testOnlyTheWholeLinkTokenIsRefusedInASubject(): void
    {
        $invoice = $this->issue->issue($this->input());

        self::assertSame('Invoice {link', $this->sent($invoice, ['subject' => 'Invoice {link'])->subject());
        self::assertSame('Invoice link}', $this->sent($invoice, ['subject' => 'Invoice link}'])->subject());
    }

    private function assertNothingStoredOrQueued(): void
    {
        self::assertSame(0, $this->sendCount());
        self::assertSame([], [...$this->mailQueue->getSent()]);
    }
}
