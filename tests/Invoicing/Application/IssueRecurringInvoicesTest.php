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
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Invoicing\Application\DeliverRecurringInvoice;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\IssueAfterTodaysRun;
use App\Invoicing\Application\IssueRecurringInvoices;
use App\Invoicing\Application\IssueRecurringOccurrence;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\RecurringAlerts;
use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringOverview;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Application\SendLimits;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Id;
use App\Tenancy\Application\Schedule\RunScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use App\Tenancy\Application\TenantClock;
use App\Tenancy\Infrastructure\Schedule\RunScheduledJobHandler;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Support\Registry\RecordingLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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

    public function testAChangeAfterTodaysRunIssuesTheTemplateAtOnce(): void
    {
        $this->finishedOn('2026-12-01');
        self::mockTime('2026-12-01 10:00:00');
        $template = $this->template();

        self::assertTrue($this->afterTodaysRun()->afterChange($template->id()));

        self::assertCount(1, $this->asyncQueue()->getSent(), 'handed to the worker, not issued in the request');
        self::assertSame([], $this->invoicesOf($template));
        $this->asyncWorker()->run();
        $this->em->clear();
        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices);
        self::assertSame('2026-12-01', $invoices[0]['recurring_occurrence']);
        self::assertCount(1, $this->sendsOf(self::text($invoices[0], 'id')), 'sent as the run sends it');
        self::assertSame('2027-01-01', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testAChangeBeforeTheRunsHourLeavesItToTheRun(): void
    {
        $this->finishedOn('2026-11-30');
        // 4:30 in Prague: the run of the day is still to come at 5:00.
        self::mockTime('2026-12-01 03:30:00');
        $template = $this->template();

        self::assertFalse($this->afterTodaysRun()->issuesOnSave(new \DateTimeImmutable('2026-12-01')));
        self::assertTrue($this->afterTodaysRun()->issuesOnSave(new \DateTimeImmutable('2026-11-30')), 'a date of the run\'s day before is overdue');
        self::assertFalse($this->afterTodaysRun()->afterChange($template->id()));

        self::assertSame([], $this->asyncQueue()->getSent());
        self::assertSame([], $this->invoicesOf($template));
    }

    public function testAChangeAfterTheRunsHourIssuesAtOnceAlsoWithoutARun(): void
    {
        // The run of the day never ran: late, or no worker. 11:00 in Prague.
        $this->finishedOn('2026-11-30');
        self::mockTime('2026-12-01 10:00:00');
        $template = $this->template();

        self::assertTrue($this->afterTodaysRun()->issuesOnSave(new \DateTimeImmutable('2026-12-01')));
        self::assertTrue($this->afterTodaysRun()->afterChange($template->id()));

        self::assertCount(1, $this->asyncQueue()->getSent());
        $this->asyncWorker()->run();
        $this->em->clear();
        self::assertCount(1, $this->invoicesOf($template));
    }

    public function testATemplateDueLaterWaitsForItsDay(): void
    {
        $this->finishedOn('2026-12-01');
        self::mockTime('2026-12-01 10:00:00');
        $template = $this->template(firstIssueOn: '2026-12-02');

        self::assertFalse($this->afterTodaysRun()->afterChange($template->id()));

        self::assertSame([], $this->asyncQueue()->getSent());
    }

    public function testTwoDispatchesIssueOnce(): void
    {
        $this->finishedOn('2026-12-01');
        self::mockTime('2026-12-01 10:00:00');
        $template = $this->template();

        $this->afterTodaysRun()->afterChange($template->id());
        $this->afterTodaysRun()->afterChange($template->id());
        self::assertCount(2, $this->asyncQueue()->getSent());
        $this->asyncWorker()->run();
        $this->em->clear();

        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices, 'the occurrence is issued once');
        self::assertSame(1, $this->sendCount(), 'and sent once');
    }

    public function testTheContactsCopyAddressesTakeACopyAsTheBoxSays(): void
    {
        $this->contactEmail('office@example.com');
        $this->copyAddress('accounts@example.com');
        $with = $this->template();
        $without = $this->template(copyToMe: false, copyToOthers: false);

        $this->runOn('2026-12-01 05:30:00');

        $sent = $this->sendsOf(self::text($this->invoicesOf($with)[0], 'id'))[0];
        self::assertSame(['["billing@example.com"]', '["accounts@example.com"]', 'office@example.com'], [$sent['recipients_to'], $sent['recipients_cc'], $sent['copy_to']]);
        $alone = $this->sendsOf(self::text($this->invoicesOf($without)[0], 'id'))[0];
        self::assertSame(['["billing@example.com"]', '[]', null], [$alone['recipients_to'], $alone['recipients_cc'], $alone['copy_to']], 'only the customer, without the copies');
    }

    public function testATemplateNotSentToTheCustomerWaitsWhateverTheCopiesSay(): void
    {
        $template = $this->template(delivery: RecurringDelivery::Review, copyToMe: true, copyToOthers: true);

        $this->runOn('2026-12-01 05:30:00');

        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices);
        self::assertSame([], $this->sendsOf(self::text($invoices[0], 'id')), 'issued, and it waits under To send');
    }

    public function testCopyFallsBackToTheOwner(): void
    {
        $owner = $this->owner();
        $template = $this->template();
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$owner->id(), $template->id()]);

        $this->runOn('2026-12-01 05:30:00');

        $sends = $this->sendsOf(self::text($this->invoicesOf($template)[0], 'id'));
        self::assertSame('owner@example.com', $sends[0]['copy_to']);
        self::assertNull($sends[0]['created_by'], 'sent on behalf of the owner, by the system');
    }

    public function testAnOwnerWhoLeftGetsNoCopy(): void
    {
        $owner = $this->owner(member: false);
        $template = $this->template();
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$owner->id(), $template->id()]);

        $this->runOn('2026-12-01 05:30:00');

        $sends = $this->sendsOf(self::text($this->invoicesOf($template)[0], 'id'));
        self::assertCount(1, $sends, 'still sent to the customer');
        self::assertNull($sends[0]['copy_to'], 'no copy to someone who no longer works here');
    }

    public function testAnOwnerWhoLeftGetsNoAlert(): void
    {
        $owner = $this->owner(member: false);
        $template = $this->template();
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$owner->id(), $template->id()]);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        self::assertNotNull($this->templateRow($template->id())['last_failure_code']);
        self::assertSame([], $this->gateway->messages());
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
        $other = $this->template();
        $this->connection->executeStatement('DELETE FROM firm_mail_server');

        $this->runOn('2026-12-01 05:30:00');

        $invoices = $this->invoicesOf($template);
        self::assertCount(1, $invoices, 'issued');
        self::assertSame(0, $this->sendCount(), 'not sent');
        self::assertEqualsCanonicalizing([$invoices[0]['id'], self::text($this->invoicesOf($other)[0], 'id')], $this->toSend());
        $row = $this->templateRow($template->id());
        self::assertSame(['send.no_server', '2027-01-01'], [$row['last_failure_code'], $row['next_occurrence']]);
        self::assertNotNull($row['last_failure_at']);
        self::assertSame('send.no_server', $this->templateRow($other->id())['last_failure_code'], 'tried on its own, not held for a budget');
        self::assertSame(2, $this->templates->failing());
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

    public function testASpentBudgetHoldsTheFirmsOtherTemplatesWithOneAlert(): void
    {
        $this->contactEmail('office@example.com');
        $templates = [];
        for ($i = 0; $i < 5; ++$i) {
            $templates[] = $this->template();
        }
        // The system's share of the day is spent; what is left belongs to people.
        $limiter = self::getContainer()->get('test.invoice_send_firm_limiter');
        $limiter->create($this->firm->id())->consume(400);

        $this->runOn('2026-12-01 05:30:00');

        self::assertSame(0, $this->sendCount(), 'nothing was sent');
        self::assertSame(100, $limiter->create($this->firm->id())->consume(0)->getRemainingTokens(), 'the run took none of the rest');
        foreach ($templates as $template) {
            self::assertCount(1, $this->invoicesOf($template), 'each still issued');
            self::assertSame('send.rate_limited', $this->templateRow($template->id())['last_failure_code']);
            self::assertSame('2026-12-01', $this->templateRow($template->id())['last_alert_on']);
        }
        self::assertSame(5, $this->templates->inReview(), 'all wait to be sent');
        $messages = $this->gateway->messages();
        self::assertCount(1, $messages, 'one alert for the firm, not one per template');
        self::assertSame(['office@example.com'], $messages[0]->to);
        self::assertSame('5 pravidelných faktur se vystavilo, ale neodeslalo', $messages[0]->subject);
        self::assertSame(5, substr_count($messages[0]->text, 'Example Customer a.s.'), 'each held template is named');
        self::assertTrue(self::getContainer()->get(SendLimits::class)->take('019a0000-0000-7000-8000-00000000002a', $this->firm->id()), 'a person can still send');

        // The same day again tells nothing new.
        $this->runOn('2026-12-01 09:00:00');
        self::assertCount(1, $this->gateway->messages());
    }

    public function testTheAlertFallsBackToTheOwnerInEnglish(): void
    {
        $owner = $this->owner();
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

    public function testAFailureBeforeAnUnexpectedErrorIsStillTold(): void
    {
        $this->contactEmail('office@example.com');
        $archived = $this->customer();
        $this->em->flush();
        $refused = $this->template(customer: $archived);
        $this->template();
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->partyNow($archived), $this->firmNow(), true);
        $this->em->flush();
        // The second template breaks on what no rule foresaw: the table of invoice lines is gone.
        $this->connection->executeStatement('ALTER TABLE invoice_line RENAME TO invoice_line_gone');
        try {
            $this->runOn('2026-12-01 05:30:00');
            self::fail('The run gives the period back.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
        } finally {
            $this->connection->executeStatement('ALTER TABLE invoice_line_gone RENAME TO invoice_line');
        }

        $messages = $this->gateway->messages();
        self::assertCount(1, $messages, 'the refusal before the error is told');
        self::assertStringContainsString('archivovaný', $messages[0]->text);
        self::assertSame('2026-12-01', $this->templateRow($refused->id())['last_alert_on']);
    }

    public function testRunningOneTemplateWithNothingDueLeavesItsFailure(): void
    {
        $template = $this->template();
        $this->connection->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'send.no_server', last_failure_at = '2026-11-01 05:30:00' WHERE id = ?", [$template->id()]);

        self::mockTime('2026-11-20 10:00:00');
        $this->em->clear();
        self::getContainer()->get(IssueRecurringInvoices::class)->runOne($template->id());

        self::assertSame('send.no_server', $this->templateRow($template->id())['last_failure_code'], 'not due: left as it is');
        self::assertSame([], $this->invoicesOf($template));
    }

    public function testRunningOnePausedTemplateLeavesItAsItIs(): void
    {
        $template = $this->template();
        self::getContainer()->get(\App\Invoicing\Application\RecurringLifecycle::class)->pause($template->id());
        $this->connection->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'send.no_server', last_failure_at = '2026-11-01 05:30:00' WHERE id = ?", [$template->id()]);
        $this->finishedOn('2026-12-01');
        self::mockTime('2026-12-01 10:00:00');

        self::assertFalse($this->afterTodaysRun()->afterChange($template->id()), 'a paused template is not handed to the worker');
        $this->em->clear();
        self::getContainer()->get(IssueRecurringInvoices::class)->runOne($template->id());

        self::assertSame('send.no_server', $this->templateRow($template->id())['last_failure_code']);
        self::assertSame([], $this->invoicesOf($template));
    }

    public function testRunningOneRefusedTemplateTellsTheFirm(): void
    {
        $this->contactEmail('office@example.com');
        $template = $this->template();
        $this->connection->executeStatement('DELETE FROM party_address');
        self::mockTime('2026-12-01 10:00:00');
        $this->em->clear();

        self::getContainer()->get(IssueRecurringInvoices::class)->runOne($template->id());

        self::assertSame('invoice.problem.customer_address_incomplete', $this->templateRow($template->id())['last_failure_code']);
        $messages = $this->gateway->messages();
        self::assertCount(1, $messages);
        self::assertSame(['office@example.com'], $messages[0]->to);
    }

    public function testASpentBudgetHoldsOnlyWhatWouldBeSentNow(): void
    {
        $this->contactEmail('office@example.com');
        // More than three days late before the budget runs out: held for review, not for the budget.
        $lateReview = $this->template('2026-11-25', delivery: RecurringDelivery::Review);
        $daily = $this->template('2026-12-01', RecurrenceUnit::Day);
        $review = $this->template(delivery: RecurringDelivery::Review);
        $lateSend = $this->template('2026-11-25');
        $limiter = self::getContainer()->get('test.invoice_send_firm_limiter');
        $limiter->create($this->firm->id())->consume(400);

        $this->runOn('2026-12-03 05:30:00');

        self::assertSame(['2026-12-01', '2026-12-02', '2026-12-03'], array_column($this->invoicesOf($daily), 'recurring_occurrence'), 'each date is issued and held');
        self::assertSame('send.rate_limited', $this->templateRow($daily->id())['last_failure_code']);
        foreach ([$lateReview, $review, $lateSend] as $template) {
            self::assertCount(1, $this->invoicesOf($template));
            self::assertNull($this->templateRow($template->id())['last_failure_code'], 'it waits for review anyway');
        }
        self::assertSame(0, $this->sendCount());
    }

    public function testAnUnexpectedErrorWhileSendingStopsThatTemplatesRun(): void
    {
        $template = $this->template('2026-12-01', RecurrenceUnit::Day);
        $this->connection->executeStatement('ALTER TABLE invoice_send RENAME TO invoice_send_gone');
        try {
            $this->runOn('2026-12-02 05:30:00');
        } finally {
            $this->connection->executeStatement('ALTER TABLE invoice_send_gone RENAME TO invoice_send');
        }

        self::assertSame(['2026-12-01'], array_column($this->invoicesOf($template), 'recurring_occurrence'), 'the next date waits for the next run');
        $row = $this->templateRow($template->id());
        self::assertSame(['send.unexpected', '2026-12-02'], [$row['last_failure_code'], $row['next_occurrence']]);
    }

    public function testWhatWentWrongIsLoggedWithTheTemplate(): void
    {
        $refused = $this->template();
        $this->connection->executeStatement('DELETE FROM party_address');
        $logger = new RecordingLogger();

        $this->runWith($logger, '2026-12-01 05:30:00');

        self::assertSame(['warning'], array_column($logger->records, 'level'));
        self::assertSame(['template' => $refused->id(), 'code' => 'invoice.problem.customer_address_incomplete'], $logger->contexts[0]);
    }

    public function testAnUnexpectedErrorWhileSendingIsLoggedWithTheInvoice(): void
    {
        $template = $this->template();
        $logger = new RecordingLogger();
        $this->connection->executeStatement('ALTER TABLE invoice_send RENAME TO invoice_send_gone');
        try {
            $this->runWith($logger, '2026-12-01 05:30:00');
        } finally {
            $this->connection->executeStatement('ALTER TABLE invoice_send_gone RENAME TO invoice_send');
        }

        self::assertSame(['error', 'warning'], array_column($logger->records, 'level'));
        $context = $logger->contexts[0];
        self::assertSame([self::text($this->invoicesOf($template)[0], 'id'), $template->id()], [$context['invoice'] ?? null, $context['template'] ?? null]);
        self::assertInstanceOf(\Throwable::class, $context['exception'] ?? null);
    }

    public function testAlertsGoToEachRecipientInEachLanguage(): void
    {
        $this->contactEmail('office@example.com');
        $this->template();
        $this->template(language: DocumentLanguage::English);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        $subjects = array_map(static fn (MailMessage $message): string => $message->subject, $this->gateway->messages());
        sort($subjects);
        self::assertSame(['A recurring invoice could not be issued', 'Pravidelnou fakturu se nepodařilo vystavit'], $subjects, 'one mail per language');
    }

    public function testWithoutAContactEachOwnerHearsOfTheirOwn(): void
    {
        $nobody = $this->template();
        $first = $this->template();
        $second = $this->template();
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$this->owner()->id(), $first->id()]);
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$this->member('colleague@example.com')->id(), $second->id()]);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        $to = array_map(static fn (MailMessage $message): array => $message->to, $this->gateway->messages());
        sort($to);
        self::assertSame([['colleague@example.com'], ['owner@example.com']], $to, 'a template nobody can hear of does not stop the others');
        self::assertNull($this->templateRow($nobody->id())['last_alert_on']);
    }

    public function testAContactThatIsNoAddressFallsBackToTheOwner(): void
    {
        $this->contactEmail('office@example.com');
        $template = $this->template();
        $this->connection->executeStatement('UPDATE recurring_invoice SET owner_id = ? WHERE id = ?', [$this->owner()->id(), $template->id()]);
        $this->connection->executeStatement("UPDATE firm_invoicing SET contact_email = 'office at example' WHERE legal_entity_id = ?", [$this->firm->id()]);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        self::assertSame([['owner@example.com']], array_map(static fn (MailMessage $message): array => $message->to, $this->gateway->messages()));
    }

    public function testTheContactAddressIsTrimmed(): void
    {
        $this->contactEmail('office@example.com');
        $this->template();
        $this->connection->executeStatement("UPDATE firm_invoicing SET contact_email = ' office@example.com ' WHERE legal_entity_id = ?", [$this->firm->id()]);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        self::assertSame([['office@example.com']], array_map(static fn (MailMessage $message): array => $message->to, $this->gateway->messages()));
    }

    public function testATemplateToldAlreadyTodayDoesNotStopTheOthers(): void
    {
        $this->contactEmail('office@example.com');
        $this->template();
        $this->connection->executeStatement('DELETE FROM party_address');
        $this->runOn('2026-12-01 05:30:00');
        self::assertCount(1, $this->gateway->messages());

        // A template in another language fails later the same day; the first is not told twice.
        $this->template('2026-12-01', language: DocumentLanguage::English);
        $this->connection->executeStatement('DELETE FROM party_address');
        $this->runOn('2026-12-01 09:00:00');

        $messages = $this->gateway->messages();
        self::assertCount(2, $messages);
        self::assertSame('A recurring invoice could not be issued', $messages[1]->subject);
    }

    public function testTheAlertIsEscapedForItsHtmlPartAndNamedForItsDay(): void
    {
        $this->contactEmail('office@example.com');
        $template = $this->template(language: DocumentLanguage::English);
        $this->connection->executeStatement("UPDATE party SET display_name = 'O''Brien & Sons' WHERE id = ?", [$this->customer->id()]);
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        $message = $this->gateway->messages()[0];
        self::assertStringContainsString("O'Brien & Sons", $message->text);
        self::assertStringContainsString('O&apos;Brien &amp; Sons', $message->html);
        self::assertStringContainsString("<br>\n", $message->html);
        self::assertStringNotContainsString('<br />', $message->html);
        self::assertSame('<recurring-alert.'.$template->id().'.20261201@example.com>', $message->messageId, 'one template: named by it');
    }

    public function testADigestIsNamedByTheFirm(): void
    {
        $this->contactEmail('office@example.com');
        $this->template();
        $this->template();
        $this->connection->executeStatement('DELETE FROM party_address');

        $this->runOn('2026-12-01 05:30:00');

        $messages = $this->gateway->messages();
        self::assertCount(1, $messages);
        self::assertSame('<recurring-alert.'.$this->firm->id().'.20261201@example.com>', $messages[0]->messageId);
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
        bool $copyToMe = true,
        bool $copyToOthers = true,
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
            'Example hosting',
            $copyToMe,
            $copyToOthers,
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
     *
     * @phpstan-impure
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

    /**
     * A user who works for the tenant as a member, or one who has left it.
     */
    private function owner(bool $member = true): User
    {
        $owner = new User('owner@example.com', 'hash', 'Example Owner');
        $this->em->persist($owner);
        if ($member) {
            $this->em->persist(new Membership($owner, Role::Member));
        }
        $this->em->flush();

        return $owner;
    }

    private function member(string $email): User
    {
        $user = new User($email, 'hash', 'Example Colleague');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, Role::Member));
        $this->em->flush();

        return $user;
    }

    private function runWith(RecordingLogger $logger, string $moment): void
    {
        self::mockTime($moment);
        $this->em->clear();
        $container = self::getContainer();
        $run = new IssueRecurringInvoices(
            $container->get(RecurringInvoices::class),
            $container->get(IssueRecurringOccurrence::class),
            $container->get(DeliverRecurringInvoice::class),
            $container->get(RecurringAlerts::class),
            $container->get(TransactionalWork::class),
            $container->get(TenantClock::class),
            $container->get(ClockInterface::class),
            $logger,
        );
        $run->run(null);
        $this->em->clear();
    }

    private function copyAddress(string $address): void
    {
        $customer = $this->em->find(Party::class, $this->customer->id()) ?? throw new \LogicException('The customer is stored.');
        $this->em->persist(new PartyEmail($customer, $address, EmailPurpose::Copy));
        $this->em->flush();
        $this->em->clear();
    }

    private function afterTodaysRun(): IssueAfterTodaysRun
    {
        $this->em->clear();

        return self::getContainer()->get(IssueAfterTodaysRun::class);
    }

    private function finishedOn(string $period): void
    {
        $this->connection->executeStatement(
            'INSERT INTO scheduled_job_run (id, tenant_id, job, last_period, finished_at) VALUES (?, ?, ?, ?, ?)',
            [Id::generate(), $this->tenant->toString(), IssueRecurringInvoices::NAME, $period, $period.' 04:10:00'],
        );
    }

    private function asyncQueue(): InMemoryTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return $queue;
    }

    private function asyncWorker(): RunMessages
    {
        return new RunMessages($this->asyncQueue(), self::getContainer()->get(MessageBusInterface::class));
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
