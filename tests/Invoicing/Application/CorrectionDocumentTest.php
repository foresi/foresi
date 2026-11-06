<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\CorrectInvoice;
use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\CorrectionRules;
use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoiceSums;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueRules;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\LinePricing;
use App\Invoicing\Application\StoredInvoice;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Party\Support\FirmAccounts;

/**
 * Credit and debit notes of an invoice of 1 000 at 21 % and 500 at 12 %, taxed on
 * 1 November 2026.
 */
final class CorrectionDocumentTest extends InvoiceTestCase
{
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

    public function testACreditNoteNamesItsInvoiceAndAsksForNothing(): void
    {
        $original = $this->original();

        $credit = $this->issue->issue($this->correction($original, [$this->credit('400.00')]));

        self::assertSame(DocumentKind::CorrectionDocument, $credit->kind());
        self::assertSame($original->id(), $credit->correctedInvoiceId());
        self::assertSame('Returned goods', $credit->correctionReason());
        self::assertSame(CorrectionType::Change, $credit->correctionType());
        self::assertSame('2026-11-20', $credit->decisiveDate()?->format('Y-m-d'));
        self::assertSame('2026-11-20', $credit->taxPointDate()?->format('Y-m-d'));
        self::assertSame(-48400, $credit->payable()->minor());
        self::assertFalse($credit->deductsAdvances());
        self::assertStringStartsWith('O7', $credit->number());
    }

    public function testADebitNoteIsAllowed(): void
    {
        $original = $this->original();

        $debit = $this->issue->issue($this->correction($original, [$this->line('200.00')]));

        self::assertSame(24200, $debit->payable()->minor());
    }

    public function testAnErrorTakesTheTaxPointOfTheOriginal(): void
    {
        $original = $this->original();
        $error = $this->correction($original, [$this->credit('100.00')], CorrectionType::Error, taxPoint: '2026-11-01');

        self::assertSame('2026-11-01', $this->issue->issue($error)->taxPointDate()?->format('Y-m-d'));
        $this->assertRefusedFor('invoice.problem.correction_tax_point', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00')], CorrectionType::Error)));
    }

    public function testAChangeIsTaxedOnTheDecisiveDate(): void
    {
        $original = $this->original();

        $this->assertRefusedFor('invoice.problem.correction_tax_point', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00')], taxPoint: '2026-11-01')));
    }

    public function testTheLinkIsRequired(): void
    {
        $this->original();

        $this->assertRefusedFor('invoice.problem.correction_link_missing', fn (): Invoice => $this->issue->issue($this->input([$this->credit('100.00')], ['seriesId' => null, 'kind' => DocumentKind::CorrectionDocument])));
    }

    public function testOnlyAnIssuedInvoiceInPlaceIsCorrected(): void
    {
        $original = $this->original();
        $advance = $this->issue->issue($this->input(null, ['seriesId' => null, 'kind' => DocumentKind::Advance, 'variableSymbol' => '']));
        $this->assertRefusedFor('invoice.problem.correction_original_invalid', fn (): Invoice => $this->issue->issue($this->correction($advance, [$this->credit('100.00')])));

        $this->trash->trash($original->id(), 'Wrong customer');
        $this->assertRefusedFor('invoice.problem.correction_original_invalid', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00')])));
    }

    public function testTheCustomerStays(): void
    {
        $original = $this->original();
        $other = $this->customer(null);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.correction_customer', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00')], overrides: ['customerId' => $other->id()])));
    }

    public function testReasonTypeAndDecisiveDateAreRequired(): void
    {
        $original = $this->original();
        $empty = new CorrectionInput($original->id(), ' ', null, null);

        $problems = $this->refusalOf(fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00')], correction: $empty)));

        self::assertContains('invoice.problem.correction_reason_missing', $problems);
        self::assertContains('invoice.problem.correction_type_missing', $problems);
        self::assertContains('invoice.problem.correction_decisive_date_missing', $problems);
        $long = new CorrectionInput($original->id(), str_repeat('x', Invoice::MAX_CORRECTION_REASON_LENGTH + 1), CorrectionType::Change, new \DateTimeImmutable('2026-11-20'));
        $this->assertRefusedFor('invoice.problem.too_long', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00')], correction: $long)));
    }

    public function testEachLineKeepsARateOfTheOriginal(): void
    {
        $original = $this->issue->issue($this->input([$this->line('1000.00')]));

        $this->assertRefusedFor('invoice.problem.correction_rate_unknown', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('100.00', VatRateKind::Reduced)])));
    }

    public function testNoRateIsCreditedBelowZero(): void
    {
        $original = $this->original();
        $this->issue->issue($this->correction($original, [$this->credit('600.00'), $this->credit('200.00', VatRateKind::Reduced)]));

        $this->assertRefusedFor('invoice.problem.correction_over_original', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('400.01')])));
        $this->assertRefusedFor('invoice.problem.correction_over_original', fn (): Invoice => $this->issue->issue($this->correction($original, [$this->credit('301.00', VatRateKind::Reduced)])));

        $last = $this->issue->issue($this->correction($original, [$this->credit('400.00'), $this->credit('300.00', VatRateKind::Reduced)]));
        self::assertSame(-82000, $last->payable()->minor());
    }

    /**
     * Two credits saved at once: the second one's rules ran before the first one
     * committed, so only the check under the lock of the invoice sees the first.
     */
    public function testTheSecondOfTwoCreditsSavedAtOnceIsRefused(): void
    {
        $original = $this->original();
        $this->issue->issue($this->correction($original, [$this->credit('600.00')]));
        $issue = $this->withRulesBlindToCorrections();

        $this->assertRefusedFor('invoice.problem.correction_over_original', fn (): Invoice => $issue->issue($this->correction($original, [$this->credit('500.00')])));

        $this->em->clear();
        self::assertCount(1, $this->store->correctionsOf($this->store->get($original->id())));
    }

    public function testADebitNoteMakesRoomForACredit(): void
    {
        $original = $this->original();
        $this->issue->issue($this->correction($original, [$this->line('300.00')]));

        $credit = $this->issue->issue($this->correction($original, [$this->credit('1300.00')]));

        self::assertSame(-157300, $credit->payable()->minor());
    }

    public function testATrashedCorrectionFreesItsAmount(): void
    {
        $original = $this->original();
        $first = $this->issue->issue($this->correction($original, [$this->credit('1000.00')]));
        $this->trash->trash($first->id(), 'Issued twice');

        $second = $this->issue->issue($this->correction($original, [$this->credit('1000.00')]));

        self::assertSame(-121000, $second->payable()->minor());
    }

    public function testANonPayerCorrectsWithoutVat(): void
    {
        $this->firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '27082440');
        $this->series = $this->seriesOf($this->firm);
        $this->em->flush();
        $plain = fn (string $price, string $quantity = '1'): LineInput => new LineInput('Work', Quantity::fromString($quantity), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::NotApplicable, null, null, Percent::fromString('0'));
        $original = $this->issue->issue($this->input([$plain('1000.00')], ['firmId' => $this->firm->id(), 'seriesId' => $this->series->id()]));

        $credit = $this->issue->issue($this->correction($original, [$plain('250.00', '-1')], overrides: ['firmId' => $this->firm->id()]));

        self::assertSame(0, $credit->vat()->minor());
        self::assertSame(-25000, $credit->payable()->minor());
    }

    public function testAnEditKeepsTheLink(): void
    {
        $original = $this->original();
        $other = $this->original();
        $credit = $this->issue->issue($this->correction($original, [$this->credit('100.00')]));

        $moved = new CorrectionInput($other->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20'));
        $this->assertRefusedFor('invoice.problem.correction_link_locked', fn () => $this->edited($credit, $this->correction($original, [$this->credit('100.00')], correction: $moved, overrides: ['seriesId' => $credit->series()->id()])));

        $reworded = new CorrectionInput($original->id(), 'Damaged goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20'));
        $this->edited($credit, $this->correction($original, [$this->credit('200.00')], correction: $reworded, overrides: ['seriesId' => $credit->series()->id()]));
        $saved = $this->store->get($credit->id());
        self::assertSame($original->id(), $saved->correctedInvoiceId());
        self::assertSame('Damaged goods', $saved->correctionReason());
        self::assertSame(-24200, $saved->payable()->minor());
    }

    public function testTheFormOpensWithTheInvoiceNegated(): void
    {
        $original = $this->original();

        $draft = self::getContainer()->get(CorrectInvoice::class)->valuesFrom($original);

        self::assertSame(DocumentKind::CorrectionDocument, $draft->kind);
        self::assertSame($original->id(), $draft->correctedInvoiceId);
        self::assertSame($this->customer->id(), $draft->customerId);
        self::assertSame(['-1.0000', '-1.0000'], array_map(static fn (LineInput $line): string => $line->quantity->toDecimal(), $draft->lines));
        self::assertSame([VatRateKind::Base, VatRateKind::Reduced], array_map(static fn (LineInput $line): ?VatRateKind => $line->rateKind, $draft->lines));
        self::assertSame([100000, 50000], array_map(static fn (LineInput $line): int => $line->price->minor(), $draft->lines));
    }

    public function testTheFormKeepsTheAccountOfTheInvoice(): void
    {
        FirmAccounts::add($this->em, $this->firm, self::SECOND_IBAN);
        $this->em->flush();
        $original = $this->issue->issue($this->input([$this->line('1000.00')], ['variableSymbol' => '', 'bankAccount' => self::SECOND_IBAN]));

        self::assertSame(self::SECOND_IBAN, self::getContainer()->get(CorrectInvoice::class)->valuesFrom($original)->bankAccount);
    }

    /**
     * Its lines take the rates on the day of the invoice, never those of its own tax
     * point: a decisive date before any rate is known still prices them.
     */
    public function testTheLinesArePricedOnTheDayOfTheInvoice(): void
    {
        $original = $this->original();
        $early = new CorrectionInput($original->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2012-12-31'));

        $credit = $this->issue->issue($this->correction($original, [$this->credit('100.00')], taxPoint: '2012-12-31', correction: $early));
        self::assertSame(-12100, $credit->payable()->minor());

        $this->edited($credit, $this->correction($original, [$this->credit('200.00')], taxPoint: '2012-12-31', correction: $early, overrides: ['seriesId' => $credit->series()->id()]));
        self::assertSame(-24200, $this->store->get($credit->id())->payable()->minor());
    }

    public function testAnEditIsWhatItWasIssuedAs(): void
    {
        $original = $this->original();
        $credit = $this->issue->issue($this->correction($original, [$this->credit('100.00')]));

        $this->edited($credit, $this->correction($original, [$this->credit('200.00')], overrides: ['seriesId' => $credit->series()->id(), 'kind' => DocumentKind::Invoice]));

        self::assertSame(-24200, $this->store->get($credit->id())->payable()->minor());
    }

    /**
     * An edit that its rules let through because another credit committed meanwhile
     * is refused under the lock of the invoice.
     */
    public function testAnEditIsClaimedUnderTheLock(): void
    {
        $original = $this->original();
        $this->issue->issue($this->correction($original, [$this->credit('600.00')]));
        $credit = $this->issue->issue($this->correction($original, [$this->credit('100.00')]));
        $edit = $this->built(EditInvoice::class, ['rules' => $this->rulesBlindToCorrections()]);
        $this->em->clear();

        $this->assertRefusedFor('invoice.problem.correction_over_original', fn () => $edit->edit($credit->id(), $this->correction($original, [$this->credit('500.00')], overrides: ['seriesId' => $credit->series()->id()]), $this->store->get($credit->id())->version()));
    }

    /**
     * IssueInvoice whose rules see no corrections, as rules that ran before another
     * correction committed; the check under the lock reads the store as it is.
     */
    private function withRulesBlindToCorrections(): IssueInvoice
    {
        return $this->built(IssueInvoice::class, ['rules' => $this->rulesBlindToCorrections(), 'deductions' => $this->deductions()]);
    }

    /**
     * IssueRules that see no corrections; the check under the lock reads the store as it is.
     */
    private function rulesBlindToCorrections(): IssueRules
    {
        $container = self::getContainer();
        $blind = new readonly class($this->store) implements InvoiceStore {
            public function __construct(private InvoiceStore $store)
            {
            }

            public function get(string $id): Invoice
            {
                return $this->store->get($id);
            }

            public function lock(string $id): Invoice
            {
                return $this->store->lock($id);
            }

            public function lockRow(string $id): void
            {
                $this->store->lockRow($id);
            }

            public function snapshot(string $id): StoredInvoice
            {
                return $this->store->snapshot($id);
            }

            public function add(Invoice $invoice): void
            {
                $this->store->add($invoice);
            }

            public function lines(Invoice $invoice): array
            {
                return $this->store->lines($invoice);
            }

            public function addLine(InvoiceLine $line): void
            {
                $this->store->addLine($line);
            }

            public function correctionsOf(Invoice $original): array
            {
                return [];
            }

            public function removeLine(InvoiceLine $line): void
            {
                $this->store->removeLine($line);
            }

            public function addAbandoned(AbandonedNumber $number): void
            {
                $this->store->addAbandoned($number);
            }

            public function numberTaken(LegalEntity $firm, string $key, ?Invoice $except): bool
            {
                return $this->store->numberTaken($firm, $key, $except);
            }

            public function page(InvoiceFilter $filter): InvoicePage
            {
                return $this->store->page($filter);
            }

            public function sums(InvoiceFilter $filter): InvoiceSums
            {
                return $this->store->sums($filter);
            }

            public function idsForPeriod(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->store->idsForPeriod($firm, $from, $to);
            }

            public function forAccountant(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $pending): array
            {
                return $this->store->forAccountant($firm, $from, $to, $pending);
            }

            public function lastFirmOf(string $userId): ?LegalEntity
            {
                return $this->store->lastFirmOf($userId);
            }
        };

        return $this->built(IssueRules::class, ['corrections' => new CorrectionRules($blind, $container->get(LinePricing::class))]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $given
     *
     * @return T
     */
    private function built(string $class, array $given): object
    {
        $reflection = new \ReflectionClass($class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $arguments[] = $given[$parameter->getName()] ?? ($type instanceof \ReflectionNamedType ? self::getContainer()->get($type->getName()) : null);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function original(): Invoice
    {
        return $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', kind: VatRateKind::Reduced)], ['variableSymbol' => '']));
    }

    private function credit(string $price, VatRateKind $kind = VatRateKind::Base): LineInput
    {
        return new LineInput('Work', Quantity::fromString('-1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, $kind, null, Percent::fromString('0'));
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function correction(Invoice $original, array $lines, CorrectionType $type = CorrectionType::Change, string $taxPoint = '2026-11-20', ?CorrectionInput $correction = null, array $overrides = []): InvoiceInput
    {
        return $this->input($lines, [
            'seriesId' => null,
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable($taxPoint),
            'variableSymbol' => '',
            'correction' => $correction ?? new CorrectionInput($original->id(), 'Returned goods', $type, new \DateTimeImmutable('2026-11-20')),
            ...$overrides,
        ]);
    }
}
