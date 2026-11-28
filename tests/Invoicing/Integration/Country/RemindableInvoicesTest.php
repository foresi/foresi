<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceBalances;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\PlannedReminder;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\RemindableInvoice;
use Foresi\Invoicing\Application\RemindableInvoices;
use Foresi\Invoicing\Application\ReminderPreview;
use Foresi\Invoicing\Application\ReminderSettings;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceSend;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Which overdue invoices of a firm get a reminder on a day, one invoice per rule.
 * The open amount is not a rule here: the caller reads it from InvoiceBalances.
 */
final class RemindableInvoicesTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    private const string ZONE = 'Europe/Prague';

    private RemindableInvoices $remindable;
    private int $hashes = 0;

    protected function setUp(): void
    {
        self::mockTime('2026-11-30 10:00:00');
        parent::setUp();
        $this->remindable = self::getContainer()->get(RemindableInvoices::class);
    }

    public function testRemindableAppliesEachRule(): void
    {
        $first = $this->deliveredDue('2026-11-15');
        $this->issued('2026-11-15');                                   // never delivered
        $trashed = $this->deliveredDue('2026-11-15');
        $this->em->getConnection()->executeStatement('UPDATE invoice SET deleted_at = ? WHERE id = ?', ['2026-11-20 10:00:00', $trashed->id()]);
        $this->deliveredDue('2026-10-31', '2026-10-20');                  // due before the switch-on day
        $this->deliveredDue('2026-11-25');                                // 25 + 7 is past the day
        $this->deliveredDue('2026-11-15', customer: $this->customerWithFlag(false));
        $this->deliveredDue('2026-11-15', customer: $this->customerWithoutAddress());
        $this->reminded($this->deliveredDue('2026-11-15'), ...array_fill(0, FirmReminders::MAX_STEPS, '2026-11-20 08:00:00'));
        $this->reminded($this->deliveredDue('2026-11-15'), '2026-11-26 07:00:00'); // reminded 5 days ago
        $second = $this->reminded($this->deliveredDue('2026-11-14'), '2026-11-23 23:30:00'); // 24 November in Prague
        $sentNormally = $this->deliveredDue('2026-11-16');
        $this->sent($sentNormally);
        $this->em->flush();
        $this->em->clear();

        $due = $this->remindable->due($this->settings(), $this->today(), 50);

        self::assertEquals([
            new RemindableInvoice($second->id(), 2, new \DateTimeImmutable('2026-11-24', new \DateTimeZone(self::ZONE))),
            new RemindableInvoice($first->id(), 1, null),
            new RemindableInvoice($sentNormally->id(), 1, null),
        ], $due, 'oldest due first; an invoice send is no reminder');
        self::assertSame([$second->id()], array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $this->remindable->due($this->settings(), $this->today(), 1)));
    }

    public function testAnAdvanceIsRemindedOnlyWhenTheFirmSaysSo(): void
    {
        $invoice = $this->deliveredDue('2026-11-15');
        $advance = $this->delivered->mark($this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance, 'taxPointDate' => null, 'dueDate' => new \DateTimeImmutable('2026-11-15')]))->id());
        $this->em->clear();
        $settings = $this->settings();

        self::assertSame([$invoice->id()], array_map(static fn (RemindableInvoice $due): string => $due->invoiceId, $this->remindable->due($settings, $this->today(), 50)));
        $settings->remindAdvances(true);
        self::assertTrue($settings->remindsAdvances());
        self::assertEqualsCanonicalizing([$invoice->id(), $advance->id()], array_map(static fn (RemindableInvoice $due): string => $due->invoiceId, $this->remindable->due($settings, $this->today(), 50)));
    }

    public function testATaxDocumentOnPaymentIsNeverReminded(): void
    {
        $advance = $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance, 'taxPointDate' => null, 'dueDate' => new \DateTimeImmutable('2026-11-15')]));
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-10'), Money::of('1210.00', Currency::of('CZK')), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($advance->id(), Money::of('1210.00', Currency::of('CZK')))]));
        $document = $this->em->getConnection()->fetchOne('SELECT id FROM invoice WHERE advance_invoice_id = ?', [$advance->id()]);
        self::assertIsString($document);
        $this->delivered->mark($document);
        $this->em->clear();
        $settings = $this->settings();
        $settings->remindAdvances(true);

        self::assertSame([], $this->remindable->due($settings, $this->today(), 50));
        self::assertSame(0, self::getContainer()->get(InvoiceBalances::class)->outstanding([$document])[$document]->minor());
    }

    public function testAFullyCreditedInvoiceAndItsCorrectionsAreNeverReminded(): void
    {
        $credited = $this->deliveredDue('2026-11-15');
        $this->delivered->mark($this->correctionOf($credited, '-1000.00')->id());
        $debited = $this->deliveredDue('2026-11-16');
        $this->delivered->mark($this->correctionOf($debited, '200.00')->id());
        $this->em->clear();

        self::assertSame([$debited->id()], array_map(static fn (RemindableInvoice $due): string => $due->invoiceId, $this->remindable->due($this->settings(), $this->today(), 50)));
        self::assertSame(0, self::getContainer()->get(InvoiceBalances::class)->outstanding([$credited->id()])[$credited->id()]->minor());
    }

    public function testAPageStartsAfterTheLastInvoiceOfThePreviousOne(): void
    {
        $first = $this->deliveredDue('2026-11-14');
        $second = $this->deliveredDue('2026-11-15');
        $third = $this->deliveredDue('2026-11-15');
        $this->em->flush();
        $this->em->clear();

        $ids = [];
        $after = null;
        do {
            $page = $this->remindable->due($this->settings(), $this->today(), 1, $after);
            foreach ($page as $invoice) {
                $ids[] = $after = $invoice->invoiceId;
            }
        } while ([] !== $page);

        $sameDay = [$second->id(), $third->id()];
        sort($sameDay);
        self::assertSame([$first->id(), ...$sameDay], $ids, 'by due date, then id; each once');
    }

    public function testBelowTheMinimumIsLeftOutInTheQuery(): void
    {
        $small = $this->deliveredDue('2026-11-14');
        $this->em->getConnection()->executeStatement('UPDATE invoice SET payable_minor = 100 WHERE id = ?', [$small->id()]);
        $enough = $this->deliveredDue('2026-11-15');
        $this->em->flush();
        $this->em->clear();
        $settings = $this->settings();
        $settings->change(true, 7, 7, Money::of('500', Currency::of('CZK')), new \DateTimeImmutable('2026-11-01'));

        $due = $this->remindable->due($settings, $this->today(), 1);

        self::assertSame([$enough->id()], array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $due));
    }

    public function testNothingWhileSwitchedOff(): void
    {
        $this->deliveredDue('2026-11-15');
        $this->em->flush();
        $settings = $this->settings();
        $settings->change(false, 7, 7, null, new \DateTimeImmutable('2026-11-30'));

        self::assertSame([], $this->remindable->due($settings, $this->today(), 10));
    }

    public function testThePreviewListsTomorrowsReminders(): void
    {
        $stored = self::getContainer()->get(ReminderSettings::class);
        $settings = $stored->get();
        $settings->change(true, 7, 7, null, new \DateTimeImmutable('2026-11-01'));
        $stored->save($settings);
        $tomorrow = $this->deliveredDue('2026-11-24');                    // 24 + 7 = 1 December, tomorrow
        $this->deliveredDue('2026-11-25');                                // the day after tomorrow
        $trashed = $this->deliveredDue('2026-11-20');
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE invoice SET deleted_at = ? WHERE id = ?', ['2026-11-21 10:00:00', $trashed->id()]);
        $this->em->clear();

        $rows = self::getContainer()->get(ReminderPreview::class)->tomorrow();

        self::assertSame([$tomorrow->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $rows));
        self::assertSame($tomorrow->number(), $rows[0]->number);
        self::assertSame(1210_00, $rows[0]->payable->minor());

        // Below the minimum in the home currency, it is left out.
        $settings = $stored->get();
        $settings->change(true, 7, 7, Money::of('1300', Currency::of('CZK')), new \DateTimeImmutable('2026-11-30'));
        $this->em->flush();
        self::assertSame([], self::getContainer()->get(ReminderPreview::class)->tomorrow());
    }

    public function testThePreviewIsEmptyWhileSwitchedOff(): void
    {
        $this->deliveredDue('2026-11-24');
        $this->em->flush();

        self::assertSame([], self::getContainer()->get(ReminderPreview::class)->tomorrow());
    }

    public function testThePreviewWhileOffShowsWhatSwitchingOnTodayWouldSend(): void
    {
        $this->deliveredDue('2026-11-29');                                // due before today, the switch-on day
        $today = $this->deliveredDue('2026-11-30');                       // first reminder on 7 December
        $later = $this->deliveredDue('2026-12-20');                       // on 27 December
        $this->deliveredDue('2026-12-24');                                // 31 December, past the 30 days shown
        $this->em->flush();
        $this->em->clear();

        $planned = self::getContainer()->get(ReminderPreview::class)->ifSwitchedOnToday();

        self::assertSame(
            [[$today->id(), '2026-12-07'], [$later->id(), '2026-12-27']],
            array_map(static fn (PlannedReminder $reminder): array => [$reminder->invoice->id, $reminder->on->format('Y-m-d')], $planned),
        );
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_reminders'), 'a preview stores nothing');

        // The stored delays and minimum count, as they would once switched on.
        $stored = self::getContainer()->get(ReminderSettings::class);
        $settings = $stored->get();
        $settings->change(false, 3, 7, Money::of('1300', Currency::of('CZK')), new \DateTimeImmutable('2026-11-30'));
        $stored->save($settings);
        $this->em->flush();
        self::assertSame([], $this->plannedDays());
        $settings->change(false, 3, 7, null, new \DateTimeImmutable('2026-11-30'));
        $this->em->flush();
        self::assertSame(['2026-12-03', '2026-12-23', '2026-12-27'], $this->plannedDays());
    }

    /**
     * The days of the first reminders, were reminders switched on today.
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function plannedDays(): array
    {
        return array_map(static fn (PlannedReminder $reminder): string => $reminder->on->format('Y-m-d'), self::getContainer()->get(ReminderPreview::class)->ifSwitchedOnToday());
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function settings(): FirmReminders
    {
        $settings = FirmReminders::standard();
        $settings->change(true, 7, 7, null, new \DateTimeImmutable('2026-11-01'));

        return $settings;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-12-01', new \DateTimeZone(self::ZONE));
    }

    private function issued(string $due, string $issued = '2026-11-01', ?Party $customer = null): Invoice
    {
        $overrides = ['issueDate' => new \DateTimeImmutable($issued), 'taxPointDate' => new \DateTimeImmutable($issued), 'dueDate' => new \DateTimeImmutable($due)];
        if ($customer instanceof Party) {
            $overrides['customerId'] = $customer->id();
        }

        return $this->issue->issue($this->input(overrides: $overrides));
    }

    private function deliveredDue(string $due, string $issued = '2026-11-01', ?Party $customer = null): Invoice
    {
        return $this->delivered->mark($this->issued($due, $issued, $customer)->id());
    }

    /**
     * Reminders of the invoice, created at these times in UTC, as steps 1, 2 and so on.
     */
    private function reminded(Invoice $invoice, string ...$at): Invoice
    {
        $step = 0;
        foreach ($at as $time) {
            $this->em->persist($this->send($invoice, new \DateTimeImmutable($time), SendPurpose::Reminder, ++$step));
        }
        $this->em->flush();

        return $invoice;
    }

    private function sent(Invoice $invoice): void
    {
        $this->em->persist($this->send($invoice, new \DateTimeImmutable('2026-11-28 09:00:00'), SendPurpose::Invoice, null));
        $this->em->flush();
    }

    private function send(Invoice $invoice, \DateTimeImmutable $at, SendPurpose $purpose, ?int $step): InvoiceSend
    {
        $invoice = $this->store->get($invoice->id());
        $hash = str_pad((string) ++$this->hashes, 64, '0', \STR_PAD_LEFT);

        return InvoiceSend::queue(Id::generate(), $invoice, ['billing@example.com'], [], null, 'Reminder', 'Body {link}', null, $at, $hash, null, 'sent/'.$hash.'.pdf', $hash, purpose: $purpose, reminderStep: $step);
    }

    private function customerWithFlag(bool $send): Party
    {
        $party = $this->customer();
        self::getContainer()->get(PartyInvoicingDefaults::class)->change($party->id(), new InvoicingDefaults(14, Currency::of('CZK'), DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, $send));
        $this->em->flush();

        return $party;
    }

    private function customerWithoutAddress(): Party
    {
        $party = new Party(PartyKind::Company, 'Silent Customer a.s.', new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();

        return $party;
    }
}
