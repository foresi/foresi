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
use App\Invoicing\Application\InvoiceHistory;
use App\Invoicing\Application\InvoiceHistoryEntry;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueRecurringOccurrence;
use App\Invoicing\Application\OccurrenceTaken;
use App\Invoicing\Application\RecurringChangedMeanwhile;
use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringOccurrence;
use App\Invoicing\Application\RecurringPage;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\PartyAddress;
use App\Tenancy\Application\TenantClock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Issuing one occurrence of a template: what the invoice holds, and that an
 * occurrence is issued once, whatever calls it again. The runs in parallel
 * processes are in RecurringRaceTest.
 */
final class IssueRecurringOccurrenceTest extends RecurringTestCase
{
    private IssueRecurringOccurrence $occurrences;

    protected function setUp(): void
    {
        parent::setUp();
        // The Czech rules with a base rate of 23 % from 2027, so a tax point can show
        // which rate a line takes. Set before anything has used the country.
        $container = self::getContainer();
        $czech = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), $container->get(IsdocWriter::class), $container->get(QrPlatba::class));
        $container->set(CzechInvoicing::class, new RateChangingCountry($czech));
        $this->occurrences = $container->get(IssueRecurringOccurrence::class);
    }

    public function testIssuesWithResolvedTextAndDates(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['textBefore' => 'Quarter {quarter}/{year}', 'textAfter' => 'Day {day}']));
        self::mockTime('2026-12-03 09:00:00');

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        $row = $this->invoiceRow($invoice->id());
        self::assertSame(['PO-12', 'Hosting 12/2026', 'Quarter 4/2026', 'Day 1'], [$row['order_number'], $row['description'], $row['text_before'], $row['text_after']]);
        self::assertSame(['2026-12-03', '2026-12-03', '2026-12-17'], [$row['issue_date'], $row['tax_point_date'], $row['due_date']], 'issued today, due 14 days on');
        self::assertSame([$template->id(), '2026-12-01'], [$row['recurring_invoice_id'], $row['recurring_occurrence']]);
        self::assertSame('20260001', $row['number']);
        self::assertSame('20260001', $row['variable_symbol'], 'the symbol follows the number');
        self::assertSame(['0308', 'bank_transfer'], [$row['constant_symbol'], $row['payment_method']]);
        self::assertSame([['Hosting 12/2026', '21.00']], array_map(static fn (array $line): array => [$line['text'], $line['rate']], $this->invoiceLines($invoice->id())));
        $stored = $this->templateRow($template->id());
        self::assertSame(['2027-01-01', '2026-12-01', 1], [$stored['next_occurrence'], $stored['last_occurrence'], $stored['issued_count']]);
    }

    public function testTheTaxPointCanBeTheLastDayOfTheMonth(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['taxPoint' => TaxPointRule::LastDayOfMonth, 'dueDays' => 0]));

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        $row = $this->invoiceRow($invoice->id());
        self::assertSame(['2026-12-01', '2026-12-31', '2026-12-01'], [$row['issue_date'], $row['tax_point_date'], $row['due_date']]);
    }

    public function testTheRateComesFromTheTaxPoint(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Hosting {month/year}'), $this->line(kind: VatRateKind::Reduced, text: 'Books')]));
        self::mockTime('2027-01-02 09:00:00');

        // A late run: the December invoice is issued, and taxed, in January.
        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame([['Hosting 12/2026', '23.00'], ['Books', '12.00']], array_map(static fn (array $line): array => [$line['text'], $line['rate']], $this->invoiceLines($invoice->id())));
        self::assertSame('2027-01-02', $this->invoiceRow($invoice->id())['tax_point_date']);
    }

    public function testTodayIsTheTenantsDay(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2027-01-01')]));
        // 23:30 in UTC is already New Year's Day in Prague, the zone of the firm's country.
        self::mockTime('2026-12-31 23:30:00 UTC');

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2027-01-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        $row = $this->invoiceRow($invoice->id());
        self::assertSame(['2027-01-01', '20270001'], [$row['issue_date'], $row['number']]);
    }

    public function testTheDocumentNumberTokenTakesTheNumberGiven(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Ref {document_number}, {month/year}')], [
            'orderNumber' => 'ON-{document_number}',
            'description' => 'Invoice {document_number} for {month/year}',
            'textBefore' => 'Pay {document_number}',
            'textAfter' => '{document_number}{foo}',
        ]));

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        $row = $this->invoiceRow($invoice->id());
        self::assertSame(['ON-20260001', 'Invoice 20260001 for 12/2026', 'Pay 20260001', '20260001{foo}'], [$row['order_number'], $row['description'], $row['text_before'], $row['text_after']]);
        self::assertSame(['Ref 20260001, 12/2026'], array_column($this->invoiceLines($invoice->id()), 'text'));
    }

    public function testAnOrderNumberTooLongWithItsNumberIsRefused(): void
    {
        $long = new Series($this->firm, 'Long', SeriesFormat::fromString('SERVICE-CONTRACT-{YYYY}-{NNNNNN}'), DocumentKind::Invoice);
        $this->em->persist($long);
        $this->em->flush();
        $template = $this->save->create($this->recurringInput(overrides: ['seriesId' => $long->id(), 'orderNumber' => str_repeat('x', 47).'{document_number}']));

        self::assertSame(['invoice.problem.too_long'], $this->refusalOf(fn (): ?Invoice => $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'))));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
        self::assertSame('2026-12-01', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testTheContactsCurrentAddressIsCopied(): void
    {
        $template = $this->save->create($this->recurringInput());
        $address = $this->em->getRepository(PartyAddress::class)->findOneBy(['party' => $this->customer]);
        self::assertInstanceOf(PartyAddress::class, $address);
        $address->relocate(new Address('Moved Street 9', 'New City', '300 00', 'CZ'));
        $this->em->flush();

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        $row = $this->invoiceRow($invoice->id());
        self::assertSame(['Moved Street 9', 'New City', 'billing@example.com'], [$row['customer_address_street'], $row['customer_address_city'], $row['customer_email']]);
    }

    public function testASecondCallForTheSameOccurrenceIssuesNothing(): void
    {
        $template = $this->save->create($this->recurringInput());
        $first = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        $second = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $first);
        self::assertNull($second);
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
        $stored = $this->templateRow($template->id());
        self::assertSame(['2027-01-01', 1], [$stored['next_occurrence'], $stored['issued_count']]);
    }

    public function testAnOccurrenceOtherThanTheNextIssuesNothing(): void
    {
        $template = $this->save->create($this->recurringInput());

        self::assertNull($this->occurrences->issue($template->id(), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-01-01')));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
        self::assertSame('2026-12-01', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testAPausedTemplateIssuesNothing(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->pause($template->id());

        self::assertNull($this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01')));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
        $stored = $this->templateRow($template->id());
        self::assertSame(['paused', '2026-12-01', 0], [$stored['state'], $stored['next_occurrence'], $stored['issued_count']]);
    }

    public function testAnEndedTemplateIssuesNothing(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->end($template->id());

        self::assertNull($this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01')));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
    }

    public function testATrashedOccurrenceIsNotIssuedAgain(): void
    {
        $template = $this->save->create($this->recurringInput());
        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));
        self::assertInstanceOf(Invoice::class, $invoice);
        $this->trash->trash($invoice->id(), 'Wrong amount');
        // As if the template had lost track of it: only the invoice still knows the occurrence.
        $this->em->getConnection()->executeStatement("UPDATE recurring_invoice SET next_occurrence = '2026-12-01', last_occurrence = NULL, issued_count = 0");

        self::assertNull($this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01')));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE deleted_at IS NOT NULL'));
        $stored = $this->templateRow($template->id());
        self::assertSame(['2026-12-01', 0], [$stored['next_occurrence'], $stored['issued_count']], 'the refusal rolled the template back too');
        self::assertSame('20260002', $this->counter->peek($this->series, new \DateTimeImmutable('2026-12-01')), 'no number was used up');
    }

    public function testTheDatabaseKeepsOneInvoicePerOccurrence(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));
        $other = $this->issue->issue($this->input());
        $connection = $this->em->getConnection();
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM invoice WHERE recurring_invoice_id IS NULL'), 'an ordinary invoice has no occurrence');

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->executeStatement("UPDATE invoice SET recurring_invoice_id = :template, recurring_occurrence = '2026-12-01' WHERE id = :id", ['template' => $template->id(), 'id' => $other->id()]);
    }

    public function testANewYearOccurrenceTakesTheNewYearsNumber(): void
    {
        $template = $this->save->create($this->recurringInput());
        $december = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));
        self::mockTime('2027-01-03 09:00:00');

        $january = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2027-01-01'));

        self::assertInstanceOf(Invoice::class, $december);
        self::assertInstanceOf(Invoice::class, $january);
        self::assertSame('20260001', $this->invoiceRow($december->id())['number']);
        $row = $this->invoiceRow($january->id());
        self::assertSame(['20270001', 'Hosting 1/2027', '2027-01-01'], [$row['number'], $row['description'], $row['recurring_occurrence']]);
    }

    public function testATemplateWithoutASeriesTakesTheFirmsDefault(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['seriesId' => null]));

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame($this->catalog->defaultFor($this->firm)->id(), $this->invoiceRow($invoice->id())['series_id']);
    }

    public function testARefusalPassesThroughAndKeepsTheTemplate(): void
    {
        $template = $this->save->create($this->recurringInput());
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->customer, $this->firm, true);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_archived', fn (): ?Invoice => $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01')));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
        $stored = $this->templateRow($template->id());
        self::assertSame(['2026-12-01', 0], [$stored['next_occurrence'], $stored['issued_count']]);
    }

    public function testHistorySaysIssuedFromTheTemplate(): void
    {
        $template = $this->save->create($this->recurringInput());
        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));
        self::assertInstanceOf(Invoice::class, $invoice);

        $history = self::getContainer()->get(InvoiceHistory::class)->of($this->reloaded($invoice));

        self::assertCount(1, $history);
        self::assertSame(InvoiceHistoryEntry::ISSUED_FROM_RECURRING, $history[0]->event);
        self::assertSame([], $history[0]->fields);
        $reloaded = $this->reloaded($invoice);
        self::assertSame([$template->id(), '2026-12-01'], [$reloaded->recurringInvoiceId(), $reloaded->recurringOccurrence()?->format('Y-m-d')]);
    }

    public function testAnOrdinaryInvoiceComesFromNoTemplate(): void
    {
        $invoice = $this->reloaded($this->issue->issue($this->input()));

        self::assertNull($invoice->recurringInvoiceId());
        self::assertNull($invoice->recurringOccurrence());
        self::assertSame(InvoiceHistoryEntry::CREATED, self::getContainer()->get(InvoiceHistory::class)->of($invoice)[0]->event);
    }

    public function testAnInvoiceIsIssuedFromOneOccurrenceOnly(): void
    {
        $invoice = $this->issue->issue($this->input());
        $invoice->issuedFrom('01963c5e-0000-7000-8000-0000000000aa', new \DateTimeImmutable('2026-12-01'));

        $this->expectException(\DomainException::class);
        $invoice->issuedFrom('01963c5e-0000-7000-8000-0000000000aa', new \DateTimeImmutable('2027-01-01'));
    }

    public function testATemplateNotActiveIsNotCheckedByTheRules(): void
    {
        $template = $this->save->create($this->recurringInput());
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->customer, $this->firm, true);
        $this->em->flush();
        $this->lifecycle->pause($template->id());

        self::assertNull($this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01')), 'nothing to issue, so nothing to refuse');
    }

    public function testTheOccurrenceIsTakenOnlyFromAnActiveTemplateOnItsNextDate(): void
    {
        $paused = $this->save->create($this->recurringInput());
        $this->lifecycle->pause($paused->id());
        $active = $this->save->create($this->recurringInput());
        $invoice = $this->issue->issue($this->input());

        foreach ([[$paused->id(), '2026-12-01'], [$active->id(), '2027-01-01']] as [$id, $occurrence]) {
            $this->em->clear();
            $origin = new RecurringOccurrence($this->templates, $id, new \DateTimeImmutable($occurrence), $this->templates->current($id)->version());
            try {
                self::getContainer()->get(TransactionalWork::class)->run(static function () use ($origin, $invoice): void {
                    $origin->recordIn($invoice);
                });
                self::fail('Expected the occurrence to be refused.');
            } catch (OccurrenceTaken) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testATemplateChangedWhileItsInvoiceIsBuiltIsBuiltAgain(): void
    {
        $template = $this->save->create($this->recurringInput());

        $invoice = $this->occurrencesChangedMeanwhile(2)->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice, 'the third build holds');
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
    }

    public function testATemplateThatKeepsChangingIsGivenUp(): void
    {
        $template = $this->save->create($this->recurringInput());

        $this->expectException(RecurringChangedMeanwhile::class);

        $this->occurrencesChangedMeanwhile(3)->issue($template->id(), new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'));
    }

    /**
     * Occurrences of templates that someone saves again, $times, while the invoice is built.
     */
    private function occurrencesChangedMeanwhile(int $times): IssueRecurringOccurrence
    {
        $container = self::getContainer();
        $templates = new class($this->templates, $this->em->getConnection(), $times) implements RecurringInvoices {
            public function __construct(private readonly RecurringInvoices $inner, private readonly Connection $connection, private int $times)
            {
            }

            public function get(string $id): RecurringInvoice
            {
                return $this->inner->get($id);
            }

            public function lock(string $id): RecurringInvoice
            {
                return $this->inner->lock($id);
            }

            public function current(string $id): RecurringInvoice
            {
                return $this->inner->current($id);
            }

            public function lockDuringWrite(string $id): RecurringInvoice
            {
                if ($this->times > 0) {
                    --$this->times;
                    $this->connection->executeStatement('UPDATE recurring_invoice SET version = version + 1 WHERE id = ?', [$id]);
                }

                return $this->inner->lockDuringWrite($id);
            }

            public function occurrenceIssued(string $id, \DateTimeImmutable $occurrence): bool
            {
                return $this->inner->occurrenceIssued($id, $occurrence);
            }

            public function add(RecurringInvoice $template): void
            {
                $this->inner->add($template);
            }

            public function remove(RecurringInvoice $template): void
            {
                $this->inner->remove($template);
            }

            public function lines(RecurringInvoice $template): array
            {
                return $this->inner->lines($template);
            }

            public function addLine(RecurringInvoiceLine $line): void
            {
                $this->inner->addLine($line);
            }

            public function removeLine(RecurringInvoiceLine $line): void
            {
                $this->inner->removeLine($line);
            }

            public function due(\DateTimeImmutable $today): array
            {
                return $this->inner->due($today);
            }

            public function page(RecurringFilter $filter): RecurringPage
            {
                return $this->inner->page($filter);
            }

            public function failing(): int
            {
                return $this->inner->failing();
            }

            public function inReview(?string $firmId = null): int
            {
                return $this->inner->inReview($firmId);
            }
        };

        return new IssueRecurringOccurrence($templates, $container->get(IssueInvoice::class), $container->get(TenantClock::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(string $id): array
    {
        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM invoice WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(string $id): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM invoice_line WHERE invoice_id = :id ORDER BY position', ['id' => $id]);
    }
}
