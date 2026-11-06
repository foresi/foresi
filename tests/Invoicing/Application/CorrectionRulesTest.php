<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\CorrectionRules;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\LinePricing;
use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * The rules of a corrective tax document asked one by one, on an invoice of
 * 1,000 and 500 CZK at the base rate issued on 1 November 2026.
 */
final class CorrectionRulesTest extends InvoiceTestCase
{
    public function testOnlyACorrectionTakesTheRatesOfItsInvoice(): void
    {
        $original = $this->original();
        $credit = $this->correctionOf($original, '-100.00');
        $rules = $this->rules();

        self::assertNull($rules->rateDate($this->correction($original, [$this->credit('100.00')], ['kind' => DocumentKind::Invoice])), 'no correction');
        self::assertSame('2026-11-01', $rules->rateDate($this->correction($original, [$this->credit('100.00')], ['kind' => DocumentKind::Invoice]), $credit)?->format('Y-m-d'), 'an edit is what it was issued as');
        self::assertNull($rules->rateDate($this->correction($original, [$this->credit('100.00')], ['correction' => new CorrectionInput('', 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20'))])), 'no link');
    }

    public function testOnlyACorrectionNamesWhatItCorrects(): void
    {
        $original = $this->original();
        $credit = $this->correctionOf($original, '-100.00');

        self::assertEquals([new IssueProblem('document', 'invoice.problem.correction_kind')], $this->check($this->correction($original, [$this->credit('100.00')], ['kind' => DocumentKind::Invoice])));
        self::assertSame([], $this->check($this->correction($original, [$this->credit('100.00')], ['kind' => DocumentKind::Invoice]), $credit), 'an edit is what it was issued as');
    }

    public function testTheReasonIsCountedInCharactersAndTrimmed(): void
    {
        $original = $this->original();
        $reason = ' '.str_repeat('č', Invoice::MAX_CORRECTION_REASON_LENGTH).' ';

        self::assertSame([], $this->check($this->correction($original, [$this->credit('100.00')], ['correction' => new CorrectionInput($original->id(), $reason, CorrectionType::Change, new \DateTimeImmutable('2026-11-20'))])));
    }

    public function testTheLinesOfARateAddUp(): void
    {
        $original = $this->original();

        self::assertSame([], $this->check($this->correction($original, [$this->credit('1200.00')])));
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.correction_over_original')], $this->check($this->correction($original, [$this->credit('1500.01')])));
    }

    public function testEachLineOfAnUnknownRateIsNamed(): void
    {
        $original = $this->original();

        $problems = $this->check($this->correction($original, [$this->credit('100.00'), $this->credit('10.00', VatRateKind::Reduced), $this->credit('20.00', VatRateKind::Reduced)]));

        self::assertEquals([new IssueProblem('lines.1.vat', 'invoice.problem.correction_rate_unknown'), new IssueProblem('lines.2.vat', 'invoice.problem.correction_rate_unknown')], $problems);
    }

    public function testATreatmentWithoutARateIsATreatmentOfItsOwn(): void
    {
        $original = $this->issue->issue($this->input([$this->line('1000.00', treatment: TaxTreatment::Exempt, kind: null)], ['variableSymbol' => '']));
        $line = new LineInput('Work', Quantity::fromString('-1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::NotApplicable, null, null, Percent::fromString('0'));

        self::assertEquals([new IssueProblem('lines.0.vat', 'invoice.problem.correction_rate_unknown')], $this->check($this->correction($original, [$line])));
    }

    public function testTheClaimAddsUpTheLinesOfARate(): void
    {
        $original = $this->original();

        $this->rules()->claim($this->correction($original, [$this->credit('1500.00')]), null, VatRegime::Payer, $this->country());
        $this->assertClaimRefused($this->correction($original, [$this->credit('1500.01')]));
    }

    public function testOnlyACorrectionIsClaimed(): void
    {
        $original = $this->original();
        $credit = $this->correctionOf($original, '-100.00');

        $this->rules()->claim($this->correction($original, [$this->credit('2000.00')], ['kind' => DocumentKind::Invoice]), null, VatRegime::Payer, $this->country());
        $this->assertClaimRefused($this->correction($original, [$this->credit('2000.00')], ['kind' => DocumentKind::Invoice]), $credit);
    }

    public function testTheClaimLocksTheInvoiceFirst(): void
    {
        $original = $this->original();
        $store = $this->createMock(InvoiceStore::class);
        $store->expects(self::once())->method('lockRow')->with($original->id());
        $store->method('get')->willReturn($original);
        $store->method('lines')->willReturnCallback(fn (Invoice $invoice): array => $this->store->lines($invoice));
        $store->method('correctionsOf')->willReturn([]);

        new CorrectionRules($store, self::getContainer()->get(LinePricing::class))->claim($this->correction($original, [$this->credit('100.00')]), null, VatRegime::Payer, $this->country());
    }

    /**
     * Each line that reaches the claim counts from zero, even at a rate the invoice
     * has not got: the check before it names those, the claim never does.
     */
    public function testALineOfAnUnknownRateCountsFromZero(): void
    {
        $original = $this->original();

        $this->rules()->claim($this->correction($original, [$this->credit('0.00', VatRateKind::Reduced)]), null, VatRegime::Payer, $this->country());
        $this->assertClaimRefused($this->correction($original, [$this->credit('0.01', VatRateKind::Reduced)]));
    }

    public function testACorrectionAtAnUnknownRateCountsFromZero(): void
    {
        $original = $this->original();
        $other = $this->issue->issue($this->input([$this->line('1000.00', kind: VatRateKind::Reduced)], ['variableSymbol' => '']));
        $zero = $this->issue->issue($this->correction($other, [$this->credit('0.00', VatRateKind::Reduced)]));
        $cent = $this->issue->issue($this->correction($other, [$this->credit('0.01', VatRateKind::Reduced)]));

        $this->claimWith($zero, $this->correction($original, [$this->credit('100.00')]));
        $this->expectException(IssueRefused::class);
        $this->claimWith($cent, $this->correction($original, [$this->credit('100.00')]));
    }

    public function testAnEditCountsTheCorrectionsAfterItself(): void
    {
        $original = $this->original();
        $first = $this->correctionOf($original, '-100.00');
        $this->correctionOf($original, '-1000.00');

        $this->assertClaimRefused($this->correction($original, [$this->credit('600.00')]), $first);
    }

    public function testTheRecordTrimsTheReason(): void
    {
        $original = $this->original();
        $credit = $this->correctionOf($original, '-100.00');

        CorrectionRules::record($credit, new CorrectionInput($original->id(), '  Damaged goods  ', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')));

        self::assertSame('Damaged goods', $credit->correctionReason());
    }

    public function testTheRecordRefusesAMissingType(): void
    {
        $credit = $this->correctionOf($this->original(), '-100.00');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('CorrectionRules refuses a missing type.');
        CorrectionRules::record($credit, new CorrectionInput((string) $credit->correctedInvoiceId(), 'Returned goods', null, new \DateTimeImmutable('2026-11-20')));
    }

    public function testTheRecordRefusesAMissingDecisiveDate(): void
    {
        $credit = $this->correctionOf($this->original(), '-100.00');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('CorrectionRules refuses a missing decisive date.');
        CorrectionRules::record($credit, new CorrectionInput((string) $credit->correctedInvoiceId(), 'Returned goods', CorrectionType::Change, null));
    }

    public function testACorrectionKeepsWhatItCorrectsAndTakesANewDecisiveDate(): void
    {
        $original = $this->original();
        $credit = $this->correctionOf($original, '-100.00');

        $credit->corrects($original->id(), 'Damaged goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-25'));
        self::assertSame('2026-11-25', $credit->decisiveDate()?->format('Y-m-d'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A corrective tax document keeps the document it corrects.');
        $credit->corrects($this->original()->id(), 'Damaged goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-25'));
    }

    public function testOnlyATaxDocumentOnPaymentInTheTrashGivesUpItsPayment(): void
    {
        $invoice = $this->original();
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('Only a tax document on payment in the trash gives up its payment.');
        $this->reloaded($invoice)->givesUpPayment();
    }

    public function testATaxDocumentOnPaymentThatTaxesNothingYetIsNotReplaced(): void
    {
        $series = new Series($this->firm, 'Tax documents', SeriesFormat::fromString('D{NNN}'), DocumentKind::PaymentTaxDocument);
        $copy = DocumentParty::ofCustomer($this->customer, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), null);
        $document = new Invoice($this->firm, $series, 'D1', VatRegime::Payer, TotalRounding::None, Currency::CZK, $copy, $this->input()->change($this->customer, $copy, DocumentKind::PaymentTaxDocument), null, new \DateTimeImmutable('2026-11-01'));

        self::assertFalse($document->isReplaced());
    }

    private function claimWith(Invoice $other, InvoiceInput $input): void
    {
        $store = self::createStub(InvoiceStore::class);
        $store->method('get')->willReturnCallback(fn (string $id): Invoice => $this->store->get($id));
        $store->method('lines')->willReturnCallback(fn (Invoice $invoice): array => $this->store->lines($invoice));
        $store->method('correctionsOf')->willReturn([$other]);

        new CorrectionRules($store, self::getContainer()->get(LinePricing::class))->claim($input, null, VatRegime::Payer, $this->country());
    }

    private function assertClaimRefused(InvoiceInput $input, ?Invoice $existing = null): void
    {
        try {
            $this->rules()->claim($input, $existing, VatRegime::Payer, $this->country());
        } catch (IssueRefused $refused) {
            self::assertEquals([new IssueProblem('lines', 'invoice.problem.correction_over_original')], $refused->problems);

            return;
        }
        self::fail('Expected the claim to be refused.');
    }

    /**
     * @return list<IssueProblem>
     */
    private function check(InvoiceInput $input, ?Invoice $existing = null): array
    {
        return $this->rules()->check($input, $this->firm, $existing, VatRegime::Payer, $this->country());
    }

    private function rules(): CorrectionRules
    {
        return self::getContainer()->get(CorrectionRules::class);
    }

    private function country(): InvoicingCountry
    {
        return self::getContainer()->get(InvoicingCountries::class)->for('CZ');
    }

    private function original(): Invoice
    {
        return $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00')], ['variableSymbol' => '']));
    }

    private function credit(string $price, VatRateKind $kind = VatRateKind::Base): LineInput
    {
        return new LineInput('Work', Quantity::fromString('-1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, $kind, null, Percent::fromString('0'));
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function correction(Invoice $original, array $lines, array $overrides = []): InvoiceInput
    {
        return $this->input($lines, [
            'seriesId' => null,
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-20'),
            'variableSymbol' => '',
            'correction' => new CorrectionInput($original->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')),
            ...$overrides,
        ]);
    }
}
