<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechInvoicing;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechSupplyCodes;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechVatRates;
use Foresi\Country\Integration\Invoicing\CzechRepublic\IsdocWriter;
use Foresi\Country\Integration\Invoicing\CzechRepublic\QrPlatba;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\InvoiceHistory;
use Foresi\Invoicing\Application\InvoiceHistoryEntry;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Application\OccurrenceTaken;
use Foresi\Invoicing\Application\RecurringChangedMeanwhile;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringOccurrence;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Invoicing\Integration\Margin\IssuedRevenue;
use Foresi\Margin\Contract\RevenueLine;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Invoicing\Application\RateChangingCountry;
use Foresi\Tests\Invoicing\Application\RecurringTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    public function testTheLinesOfTheInvoiceCarryTheIdsOfTheTemplateLines(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Hosting {month/year}'), $this->line('250.00', text: 'Support')]));
        self::mockTime('2026-12-03 09:00:00');
        $templateIds = array_column($this->lineRows($template->id()), 'id');

        $invoice = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame($templateIds, array_column($this->invoiceLines($invoice->id()), 'source_line_id'));
        $revenue = $this->revenue()->document($invoice->id());
        self::assertNotNull($revenue);
        self::assertSame($templateIds, array_map(static fn (RevenueLine $line): ?string => $line->sourceLineId, $revenue->lines));
    }

    public function testATemplateLineIsFoundByItsId(): void
    {
        $template = $this->save->create($this->recurringInput([$this->line(text: 'Hosting {month/year}')]));
        $lineId = $this->lineRows($template->id())[0]['id'];
        self::assertIsString($lineId);
        $this->em->clear();

        $found = $this->revenue()->templateLine($lineId);

        self::assertNotNull($found);
        self::assertSame([$lineId, $template->id(), $this->customer->id(), 'Hosting {month/year}'], [$found->id, $found->templateId, $found->customerId, $found->text]);
        self::assertNull($this->revenue()->templateLine(Id::generate()));
    }

    private function revenue(): IssuedRevenue
    {
        $container = self::getContainer();

        return new IssuedRevenue($container->get(InvoiceStore::class), $container->get(IssuedDocumentFactory::class), $container->get(UrlGeneratorInterface::class), $this->em, $container->get(InstanceClock::class));
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

    public function testATemplateInAnAdvanceSeriesIssuesAdvances(): void
    {
        $advances = new Series('Advances', SeriesFormat::fromString('ZF{YYYY}{NNNN}'), DocumentKind::Advance);
        $this->em->persist($advances);
        $this->em->flush();
        $template = $this->save->create($this->recurringInput(overrides: ['seriesId' => $advances->id()]));
        self::mockTime('2026-12-03 09:00:00');

        $advance = $this->occurrences->issue($template->id(), new \DateTimeImmutable('2026-12-01'));

        self::assertInstanceOf(Invoice::class, $advance);
        $row = $this->invoiceRow($advance->id());
        self::assertSame(['advance', 'ZF20260001', '2026-12-03', null], [$row['kind'], $row['number'], $row['issue_date'], $row['tax_point_date']]);
        self::assertSame([['Hosting 12/2026', '21.00']], array_map(static fn (array $line): array => [$line['text'], $line['rate']], $this->invoiceLines($advance->id())));
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

    public function testTodayIsTheInstancesDay(): void
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
        $long = new Series('Long', SeriesFormat::fromString('SERVICE-CONTRACT-{YYYY}-{NNNNNN}'), DocumentKind::Invoice);
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
        self::assertSame($this->catalog->defaultFor()->id(), $this->invoiceRow($invoice->id())['series_id']);
    }

    public function testARefusalPassesThroughAndKeepsTheTemplate(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->customer->archive(new \DateTimeImmutable('2026-11-01'));
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
        $this->customer->archive(new \DateTimeImmutable('2026-11-01'));
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

        $refused = 0;
        foreach ([[$paused->id(), '2026-12-01'], [$active->id(), '2027-01-01']] as [$id, $occurrence]) {
            $this->em->clear();
            $origin = new RecurringOccurrence($this->templates, $id, new \DateTimeImmutable($occurrence), $this->templates->current($id)->version());
            try {
                self::getContainer()->get(TransactionalWork::class)->run(static function () use ($origin, $invoice): void {
                    $origin->recordIn($invoice, []);
                });
            } catch (OccurrenceTaken) {
                ++$refused;
            }
        }
        self::assertSame(2, $refused, 'both occurrences are refused');
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

            public function trashedBefore(\DateTimeImmutable $moment): array
            {
                return $this->inner->trashedBefore($moment);
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

            public function inReview(): int
            {
                return $this->inner->inReview();
            }
        };

        return new IssueRecurringOccurrence($templates, $container->get(IssueInvoice::class), $container->get(InstanceClock::class));
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
