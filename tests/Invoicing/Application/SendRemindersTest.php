<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\MailTemplates;
use App\Invoicing\Application\ReminderPreview;
use App\Invoicing\Application\SendRefused;
use App\Invoicing\Application\SendReminders;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\MailTemplateKind;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Infrastructure\Doctrine\DoctrineReminderSettings;
use App\Invoicing\Infrastructure\Doctrine\DueDateBalances;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tests\Invoicing\Infrastructure\Support\InvoiceCopies;
use App\Tests\Invoicing\Infrastructure\Support\SettledBalances;

/**
 * The daily reminder run, at 08:00 in the tenant's zone (Prague here, from the
 * firm's country). An invoice due on 10 January gets its first reminder on the
 * 17th, each next one seven days after the previous. Every outcome is read back
 * from invoice_send.
 */
final class SendRemindersTest extends SendingTestCase
{
    private const string DUE = '2027-01-10';

    private SettledBalances $balances;
    private int $hashes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Before anything uses the balances, so the run and delivery see this one.
        $this->balances = SettledBalances::install(self::getContainer(), $this->connection, self::getContainer()->get(TenantContext::class));
    }

    public function testFirstReminderSevenDaysAfterDue(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();

        $this->runOn('2027-01-16 09:00:00');
        self::assertSame([], $this->reminders($invoice));

        $this->runOn('2027-01-17 09:00:00');
        $reminders = $this->reminders($invoice);
        self::assertCount(1, $reminders);
        self::assertSame(['reminder', 1, 'queued'], [$reminders[0]['purpose'], self::integer($reminders[0], 'reminder_step'), $reminders[0]['status']]);
        self::assertSame('["billing@example.com"]', $reminders[0]['recipients_to']);
        self::assertNull($reminders[0]['created_by'], 'the run sends it, not a person');
        self::assertNull($reminders[0]['copy_to']);
        self::assertCount(1, $this->mailQueue->getSent(), 'handed to the mail worker');
    }

    public function testTheDayBeginsAtEightTenantTime(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();
        $job = self::getContainer()->get(SendReminders::class);
        $prague = new \DateTimeZone('Europe/Prague');

        // 07:59 in Prague on the 17th is still the 16th.
        self::assertSame('2027-01-16', $job->period(new \DateTimeImmutable('2027-01-17 06:59:00 UTC'), $prague));
        self::assertSame('2027-01-17', $job->period(new \DateTimeImmutable('2027-01-17 07:00:00 UTC'), $prague));
        self::assertSame('invoice-reminders', $job->name());

        $this->runOn('2027-01-17 06:59:00');
        self::assertSame([], $this->reminders($invoice));
        $this->runOn('2027-01-17 07:00:00');
        self::assertCount(1, $this->reminders($invoice));
    }

    public function testNextReminderSevenDaysAfterThePrevious(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();
        $this->runOn('2027-01-17 09:00:00');

        $this->runOn('2027-01-23 09:00:00');
        self::assertSame([1], $this->steps($invoice));

        $this->runOn('2027-01-24 09:00:00');
        self::assertSame([1, 2], $this->steps($invoice));
        self::assertSame('2027-01-24 09:00:00', self::moment($this->reminders($invoice)[1], 'created_at'));
    }

    public function testAtMostTen(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();
        for ($step = 1; $step <= FirmReminders::MAX_STEPS; ++$step) {
            $this->pastReminder($invoice, $step, '2027-01-17 09:00:00');
        }

        $this->runOn('2027-03-01 09:00:00');

        self::assertSame(range(1, FirmReminders::MAX_STEPS), $this->steps($invoice));
    }

    public function testBelowTheMinimumIsSkipped(): void
    {
        $invoice = $this->overdue();
        // The invoice owes 1,210.00 CZK.
        $this->switchOn(minimum: Money::of('1210.01', Currency::CZK));

        $this->runOn('2027-01-17 09:00:00');
        self::assertSame([], $this->reminders($invoice));

        $this->switchOn(minimum: Money::of('1210.00', Currency::CZK));
        $this->runOn('2027-01-17 09:00:00');
        self::assertSame([1], $this->steps($invoice), 'exactly the minimum is reminded');
    }

    public function testAnotherCurrencyIgnoresTheMinimum(): void
    {
        $invoice = $this->overdue();
        $this->switchOn(minimum: Money::of('100000', Currency::EUR));

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([1], $this->steps($invoice));
    }

    public function testContactFlagOffIsSkipped(): void
    {
        $this->reminding($this->customer, false);
        $invoice = $this->overdue();
        $this->switchOn();

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([], $this->reminders($invoice));
    }

    public function testDueBeforeSwitchOnIsSkipped(): void
    {
        $invoice = $this->overdue();
        $this->switchOn(on: '2027-01-11');

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([], $this->reminders($invoice));
    }

    public function testNotDeliveredIsSkipped(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['dueDate' => new \DateTimeImmutable(self::DUE)]));
        $this->switchOn();

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([], $this->reminders($invoice));
    }

    public function testSettledIsSkipped(): void
    {
        $paid = $this->overdue();
        $open = $this->overdue();
        $this->balances->pay($paid->id());
        $this->switchOn();

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([], $this->reminders($paid));
        self::assertSame([1], $this->steps($open));
    }

    public function testHundredsOfSkippedInvoicesAheadDoNotHoldBackOneThatIsDue(): void
    {
        $source = $this->overdue('2027-01-09');
        $this->balances->pay($source->id());
        // More than one firm's daily batch, all due before it: paid ones and ones below the minimum.
        foreach (InvoiceCopies::of($this->connection, $source->id(), 300) as $paid) {
            $this->balances->pay($paid);
        }
        InvoiceCopies::of($this->connection, $source->id(), 300, ['payable_minor' => 100_00]);
        $due = $this->overdue();
        $this->switchOn(minimum: Money::of('500', Currency::CZK));

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([1], $this->steps($due));
        self::assertSame(1, $this->sendCount(), 'only the one that owes enough');
    }

    public function testThePreviewLooksPastInvoicesThatOweNothing(): void
    {
        $source = $this->overdue('2027-01-09');
        $this->balances->pay($source->id());
        foreach (InvoiceCopies::of($this->connection, $source->id(), 520) as $paid) {
            $this->balances->pay($paid);
        }
        $due = $this->overdue();
        $this->switchOn();
        self::mockTime('2027-01-16 09:00:00');
        $this->em->clear();

        $rows = self::getContainer()->get(ReminderPreview::class)->tomorrow($this->firmNow());

        self::assertSame([$due->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $rows));
    }

    public function testSwitchedOffSendsNothing(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();
        $this->switchOn(enabled: false);

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([], $this->reminders($invoice));
        self::assertSame(0, $this->sendCount());
    }

    public function testGateClosedSendsNothing(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();
        // An installation without payments and without the test's allowance.
        $container = self::getContainer();
        $balances = new DueDateBalances($this->connection, $container->get(TenantContext::class));
        self::assertFalse($balances->isAuthoritative());
        $container->set(DoctrineReminderSettings::class, new DoctrineReminderSettings($container->get('doctrine.orm.entity_manager'), $balances, false));

        $this->runOn('2027-01-17 09:00:00');

        self::assertSame([], $this->reminders($invoice));
    }

    public function testAMissedDaySendsOneReminderNotTwo(): void
    {
        $invoice = $this->overdue();
        $this->switchOn();

        // The worker was down: the first reminder was due on the 17th and the second on the 24th.
        $this->runOn('2027-01-31 09:00:00');
        self::assertSame([1], $this->steps($invoice));

        // A second run on the same day sends nothing more.
        $this->runOn('2027-01-31 15:00:00');
        self::assertSame([1], $this->steps($invoice));

        $this->runOn('2027-02-07 09:00:00');
        self::assertSame([1, 2], $this->steps($invoice));
    }

    public function testReminderUsesTheReminderTemplateInTheDocumentLanguage(): void
    {
        $english = $this->overdue(language: DocumentLanguage::English);
        $czech = $this->overdue();
        self::getContainer()->get(MailTemplates::class)->save($this->firmNow(), MailTemplateKind::Reminder, DocumentLanguage::English, 'Reminder {reminder_step}: {number}', "{days_overdue} days overdue, {amount_due} to pay.\n{link}");
        $this->em->flush();
        $this->switchOn();

        $this->runOn('2027-01-18 09:00:00');

        $reminder = $this->reminders($english)[0];
        self::assertSame('Reminder 1: '.$english->number(), $reminder['subject']);
        self::assertSame("8 days overdue, 1,210.00\u{a0}CZK to pay.\n{link}", $reminder['body']);
        self::assertSame('en', $reminder['language']);
        $builtIn = $this->reminders($czech)[0];
        self::assertSame('Upomínka: faktura '.$czech->number(), $builtIn['subject'], 'the built-in Czech reminder text');
        self::assertStringContainsString("1\u{a0}210,00\u{a0}Kč", self::text($builtIn, 'body'));
    }

    public function testAReminderDoesNotLockTheNumberTwice(): void
    {
        $invoice = $this->overdue();
        $before = $this->invoiceRow($invoice);
        $this->switchOn();
        $this->runOn('2027-01-17 09:00:00');

        $this->worker->run();
        $this->em->clear();

        self::assertSame('sent', $this->reminders($invoice)[0]['status']);
        self::assertSame($before, $this->invoiceRow($invoice), 'delivered once, when the invoice itself went: version and moment stay');
        self::assertCount(1, $this->gateway->messages());
    }

    public function testASecondSendOfAStepIsRefused(): void
    {
        $invoice = $this->overdue();
        $this->sent($invoice, ['purpose' => SendPurpose::Reminder, 'reminderStep' => 1]);

        $reason = $this->refusalOfSend(fn (): InvoiceSend => $this->sent($invoice, ['purpose' => SendPurpose::Reminder, 'reminderStep' => 1]));

        self::assertSame(SendRefused::REMINDER_TAKEN, $reason);
        self::assertSame([1], $this->steps($invoice));
    }

    public function testEachFirmIsRemindedByItsOwnSettings(): void
    {
        $other = $this->firm(vatId: 'CZ12345687', registrationNumber: '12345687');
        $otherSeries = $this->seriesOf($other);
        $this->em->flush();
        $this->serverOf($other);
        $own = $this->overdue();
        $foreign = $this->delivered->mark($this->issue->issue($this->input(overrides: ['firmId' => $other->id(), 'seriesId' => $otherSeries->id(), 'dueDate' => new \DateTimeImmutable(self::DUE)]))->id());
        $this->switchOn();
        $this->switchOn(first: 3, firm: $other);

        $this->runOn('2027-01-13 09:00:00');

        self::assertSame([], $this->reminders($own));
        self::assertSame([1], $this->steps($foreign));
    }

    private function runOn(string $moment): void
    {
        self::mockTime($moment);
        $this->em->clear();
        self::getContainer()->get(SendReminders::class)->run(null);
        $this->em->clear();
    }

    private function overdue(string $due = self::DUE, DocumentLanguage $language = DocumentLanguage::Czech): Invoice
    {
        $invoice = $this->issue->issue($this->input(overrides: ['dueDate' => new \DateTimeImmutable($due), 'language' => $language]));

        return $this->delivered->mark($invoice->id());
    }

    private function switchOn(int $first = 7, int $every = 7, ?Money $minimum = null, string $on = '2027-01-01', bool $enabled = true, ?LegalEntity $firm = null): void
    {
        $firm = $this->em->find(LegalEntity::class, ($firm ?? $this->firm)->id()) ?? self::fail('No firm.');
        $settings = $this->em->getRepository(FirmReminders::class)->findOneBy(['firm' => $firm]) ?? FirmReminders::standard($firm);
        $settings->change($enabled, $first, $every, $minimum, new \DateTimeImmutable($on));
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function firmNow(): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('No firm.');
    }

    private function serverOf(LegalEntity $firm): void
    {
        $secrets = self::getContainer()->get(\App\Invoicing\Application\MailSecrets::class);
        self::getContainer()->get(\App\Invoicing\Application\MailServers::class)->save(\App\Invoicing\Domain\FirmMailServer::define($firm, 'smtp.example.com', 587, \App\Invoicing\Domain\MailEncryption::Starttls, 'other@example.com', $secrets->seal('correct-horse-battery'), 'other@example.com', 'Other Firm'));
        $this->em->flush();
    }

    private function reminding(Party $customer, bool $send): void
    {
        $customer->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, $send));
        $this->em->flush();
    }

    /**
     * A reminder sent before this test's runs, stored as the run stores it.
     */
    private function pastReminder(Invoice $invoice, int $step, string $at): void
    {
        $invoice = $this->store->get($invoice->id());
        $hash = str_pad((string) ++$this->hashes, 64, '0', \STR_PAD_LEFT);
        $this->em->persist(InvoiceSend::queue(Id::generate(), $invoice, ['billing@example.com'], [], null, 'Reminder', 'Body {link}', null, new \DateTimeImmutable($at), $hash, null, 'sent/'.$hash.'.pdf', $hash, purpose: SendPurpose::Reminder, reminderStep: $step));
        $this->em->flush();
    }

    /**
     * @return list<array<string, mixed>> oldest step first
     */
    private function reminders(Invoice $invoice): array
    {
        return $this->connection->fetchAllAssociative("SELECT * FROM invoice_send WHERE invoice_id = ? AND purpose = 'reminder' ORDER BY reminder_step", [$invoice->id()]);
    }

    /**
     * @return list<int>
     */
    private function steps(Invoice $invoice): array
    {
        return array_map(static fn (array $row): int => self::integer($row, 'reminder_step'), $this->reminders($invoice));
    }
}
