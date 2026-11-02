<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Country\CzechRepublic\Invoicing\CzechSupplyCodes;
use App\Country\CzechRepublic\Invoicing\CzechVatRates;
use App\Country\CzechRepublic\Invoicing\IsdocWriter;
use App\Country\CzechRepublic\Invoicing\QrPlatba;
use App\Identity\Domain\User;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\IssueRecurringInvoices;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringOverview;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PaymentMethod;
use App\Tenancy\Application\Schedule\RunScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use App\Tenancy\Infrastructure\Schedule\RunScheduledJobHandler;

/**
 * The daily run of recurring templates, at 05:00 in the tenant's zone (Prague
 * here, from the firm's country): what it issues, what it sends or holds for
 * review, and what it records and tells when a template's world moved. Every
 * outcome is read back from the stored rows; no mail leaves, the gateway records.
 */
final class IssueRecurringInvoicesTest extends SendingTestCase
{
    private SaveRecurringInvoice $save;
    private RecurringInvoices $templates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->save = self::getContainer()->get(SaveRecurringInvoice::class);
        $this->templates = self::getContainer()->get(RecurringInvoices::class);
    }

    public function testTheDayBeginsAtFiveTenantTime(): void
    {
        $job = self::getContainer()->get(IssueRecurringInvoices::class);
        $prague = new \DateTimeZone('Europe/Prague');

        self::assertSame('recurring-invoices', $job->name());
        self::assertSame('2026-12-31', $job->period(new \DateTimeImmutable('2027-01-01 03:59:00 UTC'), $prague));
        self::assertSame('2027-01-01', $job->period(new \DateTimeImmutable('2027-01-01 04:00:00 UTC'), $prague));
    }

    public function testTheRunIssuesAndQueuesASendWithACopy(): void
    {
        $this->contactEmail('office@example.com');
        $template = $this->template();

        $this->runOn('2026-12-01 05:30:00');

        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices);
        self::assertSame(['2026-12-01', '2026-12-01'], [$invoices[0]['recurring_occurrence'], $invoices[0]['issue_date']]);
        $sends = $this->sendsOf(self::text($invoices[0], 'id'));
        self::assertCount(1, $sends);
        self::assertSame(['["billing@example.com"]', 'office@example.com', 'invoice'], [$sends[0]['recipients_to'], $sends[0]['copy_to'], $sends[0]['purpose']]);
        self::assertSame('Faktura '.self::text($invoices[0], 'number'), $sends[0]['subject'], 'the firm\'s text in the document\'s language');
        self::assertStringContainsString('{link}', self::text($sends[0], 'body'));
        self::assertNull($sends[0]['created_by'], 'the run sends it, not a person');
        self::assertCount(1, $this->mailQueue->getSent(), 'handed to the mail worker');
        $row = $this->templateRow($template->id());
        self::assertSame(['2027-01-01', null], [$row['next_occurrence'], $row['last_failure_code']]);
    }

    public function testCopyFallsBackToTheOwner(): void
    {
        $owner = new User('owner@example.com', 'hash', 'Example Owner');
        $this->em->persist($owner);
        $this->em->flush();
        $template = $this->template();
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$owner->id(), $template->id()]);

        $this->runOn('2026-12-01 05:30:00');

        $sends = $this->sendsOf(self::text($this->invoicesOf($template)[0], 'id'));
        self::assertSame('owner@example.com', $sends[0]['copy_to']);
        self::assertNull($sends[0]['created_by'], 'sent on behalf of the owner, by the system');
    }

    public function testReviewDeliveryIssuesWithoutSending(): void
    {
        $template = $this->template(delivery: RecurringDelivery::Review);

        $this->runOn('2026-12-01 05:30:00');

        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices);
        self::assertSame(0, $this->sendCount());
        self::assertSame(1, $this->templates->inReview());
        self::assertSame([$invoices[0]['id']], $this->toSend());
        self::assertNull($this->templateRow($template->id())['last_failure_code'], 'a review by choice is no failure');
    }

    public function testMarkingDeliveredTakesItOffTheToSendList(): void
    {
        $template = $this->template(delivery: RecurringDelivery::Review);
        $this->runOn('2026-12-01 05:30:00');

        $this->delivered->mark(self::text($this->invoicesOf($template)[0], 'id'));
        $this->em->clear();

        self::assertSame(0, $this->templates->inReview());
        self::assertSame([], $this->toSend());
    }

    public function testAFailedSendKeepsItOnTheToSendList(): void
    {
        $template = $this->template(delivery: RecurringDelivery::Review);
        $this->runOn('2026-12-01 05:30:00');
        $id = self::text($this->invoicesOf($template)[0], 'id');

        $send = $this->sent($this->store->get($id));
        $this->connection->executeStatement("UPDATE invoice_send SET status = 'failed' WHERE id = ?", [$send->id()]);
        $this->em->clear();
        self::assertSame(1, $this->templates->inReview(), 'a send that failed for good did not deliver it');
        self::assertSame([$id], $this->toSend());

        $this->connection->executeStatement("UPDATE invoice_send SET status = 'queued' WHERE id = ?", [$send->id()]);
        self::assertSame(0, $this->templates->inReview(), 'a queued send is on its way');
        self::assertSame([], $this->toSend());
    }

    public function testATrashedInvoiceIsNotToSend(): void
    {
        $template = $this->template(delivery: RecurringDelivery::Review);
        $this->runOn('2026-12-01 05:30:00');

        $this->connection->executeStatement('UPDATE invoice SET deleted_at = ? WHERE id = ?', ['2026-12-01 06:00:00', self::text($this->invoicesOf($template)[0], 'id')]);

        self::assertSame(0, $this->templates->inReview());
        self::assertSame([], $this->toSend());
    }

    public function testCatchUpIssuesEachMissedOccurrenceOnce(): void
    {
        $monthly = $this->template('2027-01-01');
        $daily = $this->template('2026-12-31', RecurrenceUnit::Day);

        // The worker was down from 31 December until the morning of 3 January.
        $this->runOn('2027-01-03 05:30:00', '2026-12-30');
        $this->runOn('2027-01-03 09:00:00', '2027-01-03');

        self::assertSame(['2027-01-01'], array_column($this->invoicesOf($monthly), 'recurring_occurrence'));
        $daily = $this->invoicesOf($daily);
        self::assertSame(['2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03'], array_column($daily, 'recurring_occurrence'));
        foreach ($daily as $invoice) {
            self::assertSame('2027-01-03', $invoice['issue_date']);
            self::assertStringStartsWith('2027', self::text($invoice, 'number'), 'the new year\'s series');
        }
        self::assertSame(5, $this->sendCount(), 'none is more than 3 days late, so each is sent once');
        self::assertSame('2027-01-04', $this->templateRow($this->templateIdOf($daily[0]))['next_occurrence']);
    }

    public function testOverThreeDaysLateGoesToReview(): void
    {
        $template = $this->template('2026-12-01', RecurrenceUnit::Day);

        $this->runOn('2026-12-05 05:30:00');

        $invoices = $this->invoicesOf($template);
        self::assertSame(['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05'], array_column($invoices, 'recurring_occurrence'));
        self::assertSame([], $this->sendsOf(self::text($invoices[0], 'id')), '4 days late: held for review');
        self::assertCount(1, $this->sendsOf(self::text($invoices[1], 'id')), '3 days late: still sent');
        self::assertSame([$invoices[0]['id']], $this->toSend());
        self::assertNull($this->templateRow($template->id())['last_failure_code']);
    }

    public function testNoServerTurnsIntoReviewAndAlerts(): void
    {
        $this->contactEmail('office@example.com');
        $template = $this->template();
        $this->connection->executeStatement('DELETE FROM firm_mail_server');

        $this->runOn('2026-12-01 05:30:00');

        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices, 'issued');
        self::assertSame(0, $this->sendCount(), 'not sent');
        self::assertSame([$invoices[0]['id']], $this->toSend());
        $row = $this->templateRow($template->id());
        self::assertSame(['send.no_server', '2027-01-01'], [$row['last_failure_code'], $row['next_occurrence']]);
        self::assertNotNull($row['last_failure_at']);
        self::assertSame(1, $this->templates->failing());
        self::assertSame([], $this->gateway->attempts(), 'without a server no alert can leave; the notice carries it');
    }

    public function testSendingTheHeldInvoiceByHandEndsTheSendFailure(): void
    {
        $template = $this->template();
        $this->connection->executeStatement('DELETE FROM firm_mail_server');
        $this->runOn('2026-12-01 05:30:00');
        self::assertSame(1, $this->templates->failing());

        $this->firm = $this->firmNow();
        $this->serverFor();
        $this->sent($this->store->get(self::text($this->invoicesOf($template)[0], 'id')));
        $this->em->clear();

        self::assertSame(0, $this->templates->failing(), 'the notice is gone');
        self::assertSame([], $this->templates->page(new RecurringFilter(null, null, true, 1))->templates, 'and so is the row under failing');
        self::assertSame(0, self::getContainer()->get(RecurringOverview::class)->counts(null)['failing']);
    }

    public function testMarkingTheHeldInvoiceDeliveredEndsTheSendFailure(): void
    {
        $template = $this->template();
        $this->connection->executeStatement('DELETE FROM firm_mail_server');
        $this->runOn('2026-12-01 05:30:00');

        $this->delivered->mark(self::text($this->invoicesOf($template)[0], 'id'));
        $this->em->clear();

        self::assertSame(0, $this->templates->failing());
    }

    public function testAnIssueFailureStaysUntilARunGoesClean(): void
    {
        $template = $this->template(delivery: RecurringDelivery::Review);
        $this->connection->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'invoice.problem.customer_archived', last_failure_at = '2026-12-01 05:00:00' WHERE id = ?", [$template->id()]);

        self::assertSame(1, $this->templates->failing(), 'nothing waits to be sent, yet the issue failed');
        self::assertSame([$template->id()], array_map(static fn (RecurringInvoice $found): string => $found->id(), $this->templates->page(new RecurringFilter(null, null, true, 1))->templates));
    }

    public function testARefusedIssueIsRecordedAndOthersStillRun(): void
    {
        $this->contactEmail('office@example.com');
        $archived = $this->customer();
        $this->em->flush();
        $refused = $this->template(customer: $archived);
        $other = $this->template();
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->partyNow($archived), $this->firmNow(), true);
        $this->em->flush();

        $this->runOn('2026-12-01 05:30:00');

        self::assertSame([], $this->invoicesOf($refused));
        $row = $this->templateRow($refused->id());
        self::assertSame(['invoice.problem.customer_archived', '2026-12-01', 0], [$row['last_failure_code'], $row['next_occurrence'], self::integer($row, 'issued_count')], 'the next run tries again');
        self::assertSame('2026-12-01 05:30:00', self::moment($row, 'last_failure_at'));
        self::assertCount(1, $this->invoicesOf($other), 'the next template still ran');
        self::assertNull($this->templateRow($other->id())['last_failure_code']);
    }

    public function testAContactWithoutAnInvoicingAddressIsRecorded(): void
    {
        $template = $this->template();
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        self::assertSame([], $this->invoicesOf($template));
        self::assertSame('invoice.problem.customer_address_incomplete', $this->templateRow($template->id())['last_failure_code']);
    }

    public function testARateKindGoneOnTheTaxPointIsRecorded(): void
    {
        $container = self::getContainer();
        $czech = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), $container->get(IsdocWriter::class), $container->get(QrPlatba::class));
        $container->set(CzechInvoicing::class, new ReducedRateGoneCountry($czech));
        // Saved while the reduced rate still exists; the run issues on its tax point in 2027.
        $template = $this->template(lines: [$this->line(kind: VatRateKind::Reduced, text: 'Books')]);

        $this->runOn('2027-01-02 05:30:00');

        self::assertSame([], $this->invoicesOf($template));
        self::assertSame('invoice.problem.line_rate_missing', $this->templateRow($template->id())['last_failure_code']);
    }

    public function testASuccessClearsAnEarlierFailure(): void
    {
        $template = $this->template('2026-12-01', RecurrenceUnit::Day);
        $this->connection->executeStatement('DELETE FROM party_address');
        $this->runOn('2026-12-01 05:30:00');
        self::assertNotNull($this->templateRow($template->id())['last_failure_code']);

        $customer = $this->partyNow($this->customer);
        $this->em->persist(new PartyAddress($customer, \App\Party\Domain\AddressType::Billing, new \App\Party\Domain\Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();
        $this->runOn('2026-12-02 05:30:00');

        $row = $this->templateRow($template->id());
        self::assertSame([null, null], [$row['last_failure_code'], $row['last_failure_at']]);
        self::assertSame(['2026-12-01', '2026-12-02'], array_column($this->invoicesOf($template), 'recurring_occurrence'));
    }

    public function testAnUnexpectedErrorGivesThePeriodBack(): void
    {
        $template = $this->template();
        // Whatever no rule foresaw while issuing: here the table of invoice lines is gone.
        $this->connection->executeStatement('ALTER TABLE invoice_line RENAME TO invoice_line_gone');
        try {
            self::mockTime('2026-12-01 05:30:00');
            $this->em->clear();
            self::getContainer()->get(RunScheduledJobHandler::class)(new RunScheduledJob(IssueRecurringInvoices::NAME, '2026-12-01'));
        } finally {
            $this->connection->executeStatement('ALTER TABLE invoice_line_gone RENAME TO invoice_line');
        }

        $this->em->clear();
        self::assertNull(self::getContainer()->get(ScheduledJobRuns::class)->lastFinished(IssueRecurringInvoices::NAME), 'not finished: the next tick retries');
        $run = $this->connection->fetchAssociative('SELECT * FROM scheduled_job_run WHERE job = ?', [IssueRecurringInvoices::NAME]);
        self::assertIsArray($run);
        self::assertNotNull($run['last_error']);
        self::assertSame([], $this->invoicesOf($template), 'nothing was issued');
        self::assertNull($this->templateRow($template->id())['last_failure_code'], 'no refusal was recorded for it');
    }

    public function testAnUnexpectedErrorWhileSendingHoldsTheInvoiceAndRunsTheNextTemplate(): void
    {
        $this->contactEmail('office@example.com');
        $failing = $this->template();
        $other = $this->template(delivery: RecurringDelivery::Review);
        // Whatever no rule foresaw while sending: here the table of sends is gone.
        $this->connection->executeStatement('ALTER TABLE invoice_send RENAME TO invoice_send_gone');
        try {
            self::mockTime('2026-12-01 05:30:00');
            $this->em->clear();
            self::getContainer()->get(RunScheduledJobHandler::class)(new RunScheduledJob(IssueRecurringInvoices::NAME, '2026-12-01'));
        } finally {
            $this->connection->executeStatement('ALTER TABLE invoice_send_gone RENAME TO invoice_send');
        }

        $this->em->clear();
        self::assertSame('2026-12-01', self::getContainer()->get(ScheduledJobRuns::class)->lastFinished(IssueRecurringInvoices::NAME), 'the run finished');
        $invoices = $this->invoicesOf($failing);
        self::assertCount(1, $invoices, 'the invoice stays issued');
        self::assertCount(1, $this->invoicesOf($other), 'the next template still ran');
        self::assertSame(2, $this->templates->inReview(), 'both wait to be sent');
        $row = $this->templateRow($failing->id());
        self::assertSame(['send.unexpected', '2027-01-01'], [$row['last_failure_code'], $row['next_occurrence']]);
        self::assertCount(1, $this->gateway->messages(), 'the firm is told');

        // The same day again: the held invoice is not issued or sent a second time.
        $this->runOn('2026-12-01 09:00:00');
        self::assertCount(1, $this->invoicesOf($failing));
        self::assertSame(0, $this->sendCount());
        self::assertSame(2, $this->templates->inReview());
    }

    public function testTheAlertMailGoesOncePerDay(): void
    {
        $this->contactEmail('office@example.com');
        $archived = $this->customer();
        $this->em->flush();
        $template = $this->template(customer: $archived);
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->partyNow($archived), $this->firmNow(), true);
        $this->em->flush();

        $this->runOn('2026-12-01 05:30:00');
        // The same day again, as a given-back period or "issue now" would.
        $this->runOn('2026-12-01 09:00:00');

        $messages = $this->gateway->messages();
        self::assertCount(1, $messages);
        self::assertSame(['office@example.com'], $messages[0]->to);
        self::assertSame('invoices@example.com', $messages[0]->from, 'through the firm\'s own server');
        self::assertSame('Pravidelnou fakturu se nepodařilo vystavit', $messages[0]->subject, 'built-in, in the template\'s language');
        self::assertStringContainsString('Example Customer a.s.', $messages[0]->text);
        self::assertStringContainsString('archivovaný', $messages[0]->text);
        self::assertSame('2026-12-01', $this->templateRow($template->id())['last_alert_on']);

        $this->runOn('2026-12-02 05:30:00');
        self::assertCount(2, $this->gateway->messages(), 'the next day tells again');
    }

    public function testTheAlertFallsBackToTheOwnerInEnglish(): void
    {
        $owner = new User('owner@example.com', 'hash', 'Example Owner');
        $this->em->persist($owner);
        $this->em->flush();
        $template = $this->template(language: DocumentLanguage::English);
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$owner->id(), $template->id()]);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        $messages = $this->gateway->messages();
        self::assertCount(1, $messages);
        self::assertSame(['owner@example.com'], $messages[0]->to);
        self::assertSame('A recurring invoice could not be issued', $messages[0]->subject);
        self::assertStringContainsString('no complete invoicing address', $messages[0]->text);
    }

    public function testTheEndRuleEndsAfterTheLastOccurrence(): void
    {
        $template = $this->template('2026-12-01', RecurrenceUnit::Day, recurrence: new Recurrence(new \DateTimeImmutable('2026-12-01'), RecurrenceUnit::Day, 1, false, RecurrenceEnd::AfterCount, null, 2));

        $this->runOn('2026-12-04 05:30:00');

        self::assertSame(['2026-12-01', '2026-12-02'], array_column($this->invoicesOf($template), 'recurring_occurrence'));
        $row = $this->templateRow($template->id());
        self::assertSame(['ended', null, 2], [$row['state'], $row['next_occurrence'], self::integer($row, 'issued_count')]);
    }

    public function testAtMostTwentyFourPerTemplateInOneRun(): void
    {
        $template = $this->template('2026-11-03', RecurrenceUnit::Day, delivery: RecurringDelivery::Review);

        $this->runOn('2026-12-31 05:30:00');

        self::assertCount(IssueRecurringInvoices::MAX_PER_RUN, $this->invoicesOf($template));
        self::assertSame('2026-11-27', $this->templateRow($template->id())['next_occurrence'], 'the next run goes on from here');
    }

    public function testAPausedTemplateIsNotIssued(): void
    {
        $template = $this->template();
        self::getContainer()->get(\App\Invoicing\Application\RecurringLifecycle::class)->pause($template->id());

        $this->runOn('2026-12-01 05:30:00');

        self::assertSame([], $this->invoicesOf($template));
    }

    /**
     * @param list<LineInput>|null $lines
     */
    private function template(
        string $firstIssueOn = '2026-12-01',
        RecurrenceUnit $unit = RecurrenceUnit::Month,
        RecurringDelivery $delivery = RecurringDelivery::Send,
        ?Party $customer = null,
        ?array $lines = null,
        ?Recurrence $recurrence = null,
        DocumentLanguage $language = DocumentLanguage::Czech,
    ): RecurringInvoice {
        $this->em->clear();

        return $this->save->create(new RecurringInput(
            $this->firm->id(),
            $this->series->id(),
            ($customer ?? $this->customer)->id(),
            PaymentMethod::BankTransfer,
            self::IBAN,
            '0308',
            'PO-{month}',
            'Hosting {month/year}',
            '',
            '',
            $language,
            $lines ?? [$this->line(text: 'Hosting {month/year}')],
            $recurrence ?? new Recurrence(new \DateTimeImmutable($firstIssueOn), $unit, 1, false, RecurrenceEnd::Never, null, null),
            TaxPointRule::IssueDate,
            14,
            $delivery,
        ));
    }

    private function runOn(string $moment, ?string $lastPeriod = null): void
    {
        self::mockTime($moment);
        $this->em->clear();
        self::getContainer()->get(IssueRecurringInvoices::class)->run($lastPeriod);
        $this->em->clear();
    }

    /**
     * @return list<array<string, mixed>> oldest occurrence first
     */
    private function invoicesOf(RecurringInvoice $template): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM invoice WHERE recurring_invoice_id = ? ORDER BY recurring_occurrence', [$template->id()]);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function templateIdOf(array $invoice): string
    {
        return self::text($invoice, 'recurring_invoice_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sendsOf(string $invoiceId): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM invoice_send WHERE invoice_id = ?', [$invoiceId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateRow(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM recurring_invoice WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string> the ids the "to send" filter lists
     */
    private function toSend(): array
    {
        $this->em->clear();
        $page = $this->store->page(new InvoiceFilter(null, InvoiceFilter::ALL, '', '-issueDate', 1, toSend: true));

        return array_map(static fn (InvoiceRow $row): string => $row->id, $page->rows);
    }

    private function firmNow(): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('No firm.');
    }

    private function partyNow(Party $party): Party
    {
        return $this->em->find(Party::class, $party->id()) ?? self::fail('No contact.');
    }
}
