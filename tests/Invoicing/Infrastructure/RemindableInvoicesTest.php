<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\PlannedReminder;
use App\Invoicing\Application\RemindableInvoice;
use App\Invoicing\Application\RemindableInvoices;
use App\Invoicing\Application\ReminderPreview;
use App\Invoicing\Application\ReminderSettings;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendPurpose;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyFirmSettings;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\InvoiceTestCase;
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
        $this->deliveredDue('2026-11-15', customer: $this->customerOffForFirm($this->firm));
        $this->deliveredDue('2026-11-15', customer: $this->customerWithoutAddress());
        $this->reminded($this->deliveredDue('2026-11-15'), ...array_fill(0, FirmReminders::MAX_STEPS, '2026-11-20 08:00:00'));
        $this->reminded($this->deliveredDue('2026-11-15'), '2026-11-26 07:00:00'); // reminded 5 days ago
        $second = $this->reminded($this->deliveredDue('2026-11-14'), '2026-11-23 23:30:00'); // 24 November in Prague
        $this->otherFirmsInvoice('2026-11-15');
        $sentNormally = $this->deliveredDue('2026-11-16');
        $this->sent($sentNormally);
        $otherFirmOff = $this->deliveredDue('2026-11-17', customer: $this->customerOffForFirm($this->firm(vatId: 'CZ12345687', registrationNumber: '12345687')));
        $this->em->flush();
        $this->em->clear();

        $due = $this->remindable->due($this->firm, $this->settings(), $this->today(), 50);

        self::assertEquals([
            new RemindableInvoice($second->id(), 2, new \DateTimeImmutable('2026-11-24', new \DateTimeZone(self::ZONE))),
            new RemindableInvoice($first->id(), 1, null),
            new RemindableInvoice($sentNormally->id(), 1, null),
            new RemindableInvoice($otherFirmOff->id(), 1, null),
        ], $due, 'oldest due first; an invoice send is no reminder; a flag off for another firm does not count');
        self::assertSame([$second->id()], array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $this->remindable->due($this->firm, $this->settings(), $this->today(), 1)));
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
            $page = $this->remindable->due($this->firm, $this->settings(), $this->today(), 1, $after);
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
        $settings->change(true, 7, 7, Money::of('500', Currency::CZK), new \DateTimeImmutable('2026-11-01'));

        $due = $this->remindable->due($this->firm, $settings, $this->today(), 1);

        self::assertSame([$enough->id()], array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $due));
    }

    public function testNothingWhileSwitchedOff(): void
    {
        $this->deliveredDue('2026-11-15');
        $this->em->flush();
        $settings = $this->settings();
        $settings->change(false, 7, 7, null, new \DateTimeImmutable('2026-11-30'));

        self::assertSame([], $this->remindable->due($this->firm, $settings, $this->today(), 10));
    }

    public function testThePreviewListsTomorrowsReminders(): void
    {
        $stored = self::getContainer()->get(ReminderSettings::class);
        $settings = $stored->for($this->firm);
        $settings->change(true, 7, 7, null, new \DateTimeImmutable('2026-11-01'));
        $stored->save($settings);
        $tomorrow = $this->deliveredDue('2026-11-24');                    // 24 + 7 = 1 December, tomorrow
        $this->deliveredDue('2026-11-25');                                // the day after tomorrow
        $trashed = $this->deliveredDue('2026-11-20');
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE invoice SET deleted_at = ? WHERE id = ?', ['2026-11-21 10:00:00', $trashed->id()]);
        $this->em->clear();

        $rows = self::getContainer()->get(ReminderPreview::class)->tomorrow($this->firm);

        self::assertSame([$tomorrow->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $rows));
        self::assertSame($tomorrow->number(), $rows[0]->number);
        self::assertSame(1210_00, $rows[0]->payable->minor());

        // Below the minimum in the home currency, it is left out.
        $settings = $stored->for($this->firm);
        $settings->change(true, 7, 7, Money::of('1300', Currency::CZK), new \DateTimeImmutable('2026-11-30'));
        $this->em->flush();
        self::assertSame([], self::getContainer()->get(ReminderPreview::class)->tomorrow($this->firm));
    }

    public function testThePreviewIsEmptyWhileSwitchedOff(): void
    {
        $this->deliveredDue('2026-11-24');
        $this->em->flush();

        self::assertSame([], self::getContainer()->get(ReminderPreview::class)->tomorrow($this->firm));
    }

    public function testThePreviewWhileOffShowsWhatSwitchingOnTodayWouldSend(): void
    {
        $this->deliveredDue('2026-11-29');                                // due before today, the switch-on day
        $today = $this->deliveredDue('2026-11-30');                       // first reminder on 7 December
        $later = $this->deliveredDue('2026-12-20');                       // on 27 December
        $this->deliveredDue('2026-12-24');                                // 31 December, past the 30 days shown
        $this->em->flush();
        $this->em->clear();

        $planned = self::getContainer()->get(ReminderPreview::class)->ifSwitchedOnToday($this->firm);

        self::assertSame(
            [[$today->id(), '2026-12-07'], [$later->id(), '2026-12-27']],
            array_map(static fn (PlannedReminder $reminder): array => [$reminder->invoice->id, $reminder->on->format('Y-m-d')], $planned),
        );
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_reminders'), 'a preview stores nothing');

        // The stored delays and minimum count, as they would once switched on.
        $stored = self::getContainer()->get(ReminderSettings::class);
        $settings = $stored->for($this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('No firm.'));
        $settings->change(false, 3, 7, Money::of('1300', Currency::CZK), new \DateTimeImmutable('2026-11-30'));
        $stored->save($settings);
        $this->em->flush();
        self::assertSame([], $this->plannedDays());
        $settings->change(false, 3, 7, null, new \DateTimeImmutable('2026-11-30'));
        $this->em->flush();
        self::assertSame(['2026-12-03', '2026-12-23', '2026-12-27'], $this->plannedDays());
    }

    public function testRowsOfAnotherTenantAreIgnored(): void
    {
        $silent = $this->customerWithoutAddress();
        $flagged = $this->customer();
        $this->em->flush();
        $unreachable = $this->deliveredDue('2026-11-15', customer: $silent);
        $reachable = $this->deliveredDue('2026-11-15', customer: $flagged);
        $this->em->flush();
        $other = Id::generate();
        $connection = $this->connection();
        // Rows that name this tenant's contacts but belong to another tenant.
        $connection->executeStatement("INSERT INTO party_email (id, tenant_id, party_id, legal_entity_id, address, address_key, purpose) VALUES (?, ?, ?, NULL, 'foreign@example.com', 'foreign@example.com', 'invoices')", [Id::generate(), $other, $silent->id()]);
        $connection->executeStatement('INSERT INTO party_firm_settings (id, tenant_id, party_id, legal_entity_id, invoicing_due_days, invoicing_send_reminders) VALUES (?, ?, ?, ?, 14, ?)', [Id::generate(), $other, $flagged->id(), $this->firm->id(), false], [4 => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        $this->em->clear();

        $due = array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $this->remindable->due($this->firm, $this->settings(), $this->today(), 50));

        self::assertNotContains($unreachable->id(), $due, 'another tenant\'s address does not count');
        self::assertContains($reachable->id(), $due, 'another tenant\'s flag does not count');
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
        return array_map(static fn (PlannedReminder $reminder): string => $reminder->on->format('Y-m-d'), self::getContainer()->get(ReminderPreview::class)->ifSwitchedOnToday($this->firm));
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function settings(): FirmReminders
    {
        $settings = FirmReminders::standard($this->firm);
        $settings->change(true, 7, 7, null, new \DateTimeImmutable('2026-11-01'));

        return $settings;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-12-01', new \DateTimeZone(self::ZONE));
    }

    private function issued(string $due, string $issued = '2026-11-01', ?Party $customer = null, ?LegalEntity $firm = null): Invoice
    {
        $overrides = ['issueDate' => new \DateTimeImmutable($issued), 'taxPointDate' => new \DateTimeImmutable($issued), 'dueDate' => new \DateTimeImmutable($due)];
        if ($customer instanceof Party) {
            $overrides['customerId'] = $customer->id();
        }
        if ($firm instanceof LegalEntity) {
            $overrides['firmId'] = $firm->id();
            $overrides['seriesId'] = $this->seriesOf($firm)->id();
            $this->em->flush();
        }

        return $this->issue->issue($this->input(overrides: $overrides));
    }

    private function deliveredDue(string $due, string $issued = '2026-11-01', ?Party $customer = null): Invoice
    {
        return $this->delivered->mark($this->issued($due, $issued, $customer)->id());
    }

    private function otherFirmsInvoice(string $due): void
    {
        $other = $this->firm(vatId: 'CZ12345679', registrationNumber: '12345679');
        $this->em->flush();
        $this->delivered->mark($this->issued($due, firm: $other)->id());
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
        $party->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, $send));
        $this->em->flush();

        return $party;
    }

    private function customerOffForFirm(LegalEntity $firm): Party
    {
        $party = $this->customer();
        $this->em->flush();
        // Delivering detaches what was loaded before, the firm too.
        $row = new PartyFirmSettings($party, $this->em->find(LegalEntity::class, $firm->id()) ?? self::fail());
        $row->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, false));
        $this->em->persist($row);
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
