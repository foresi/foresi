<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\LinePricing;
use Foresi\Invoicing\Application\LineRules;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceCalculator;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * The checks an invoice and a recurring template share, asked one by one without a
 * container, so a change to them fails here first and fast.
 */
final class LineRulesTest extends TestCase
{
    private const string TAX_POINT = '2026-11-01';

    private LegalEntity $firm;

    protected function setUp(): void
    {
        $this->firm = $this->firmOf(VatStatus::Registered, 'CZ12345695', 'Example register, entry C 1');
    }

    public function testARegisteredFirmWithAVatIdAndARegisterEntryMayIssue(): void
    {
        self::assertSame([], $this->rules()->firm());
    }

    public function testAnIdentifiedPersonMayNotIssue(): void
    {
        $this->firm = $this->firmOf(VatStatus::IdentifiedPerson, 'CZ12345695', 'Example register, entry C 1');

        self::assertEquals([new IssueProblem('firm', 'invoice.problem.firm_identified_person')], $this->rules()->firm());
    }

    public function testAnIdentifiedPersonWithoutAVatIdIsRefusedForBoth(): void
    {
        $this->firm = $this->firmOf(VatStatus::IdentifiedPerson, null, 'Example register, entry C 1');

        self::assertEquals([
            new IssueProblem('firm', 'invoice.problem.firm_identified_person'),
            new IssueProblem('firm', 'invoice.problem.firm_vat_id_missing'),
        ], $this->rules()->firm());
    }

    public function testAPayerWithoutAVatIdMayNotIssue(): void
    {
        $this->firm = $this->firmOf(VatStatus::Registered, null, 'Example register, entry C 1');

        self::assertEquals([new IssueProblem('firm', 'invoice.problem.firm_vat_id_missing')], $this->rules()->firm());
    }

    public function testAFirmOutsideVatNeedsNoVatId(): void
    {
        $this->firm = $this->firmOf(VatStatus::NotRegistered, null, 'Example register, entry C 1');

        self::assertSame([], $this->rules()->firm());
    }

    public function testAFirmWithABlankRegisterEntryMayNotIssue(): void
    {
        $this->firm = $this->firmOf(VatStatus::Registered, 'CZ12345695', '  ');

        self::assertEquals([new IssueProblem('firm', 'invoice.problem.firm_register_entry_missing')], $this->rules()->firm());
    }

    public function testEveryFirmProblemIsReportedAtOnce(): void
    {
        $this->firm = $this->firmOf(VatStatus::IdentifiedPerson, null, '');

        self::assertEquals([
            new IssueProblem('firm', 'invoice.problem.firm_identified_person'),
            new IssueProblem('firm', 'invoice.problem.firm_vat_id_missing'),
            new IssueProblem('firm', 'invoice.problem.firm_register_entry_missing'),
        ], $this->rules()->firm());
    }

    public function testPrintableTextsGiveNoProblem(): void
    {
        self::assertSame([], $this->rules()->unprintable(['description' => 'Consulting', 'textAfter' => '']));
        self::assertSame([], $this->rules()->unprintable([]));
    }

    public function testEachUnprintableTextNamesItsFieldAndItsCharacters(): void
    {
        self::assertEquals([
            new IssueProblem('description', 'invoice.problem.unprintable', ['characters' => '🙂, 🙃']),
            new IssueProblem('lines.1.text', 'invoice.problem.unprintable', ['characters' => '🙃']),
        ], $this->rules()->unprintable(['description' => 'A 🙂 and 🙃 and 🙂', 'textBefore' => 'Fine', 'lines.1.text' => 'Work 🙃']));
    }

    public function testTheTextsOfLinesAreNamedByTheirFields(): void
    {
        self::assertSame([
            'lines.0.text' => 'Work',
            'lines.0.unit' => 'h',
            'lines.1.text' => 'Travel',
            'lines.1.unit' => 'km',
        ], $this->rules()->lineTexts([$this->line(), $this->line(text: 'Travel', unit: 'km')]));
        self::assertSame([], $this->rules()->lineTexts([]));
    }

    public function testAStandardLineOfAPayerPasses(): void
    {
        self::assertSame([], $this->lines([$this->line()]));
    }

    public function testADocumentWithoutLinesIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.no_lines')], $this->lines([]));
    }

    public function testADocumentMayHaveAsManyLinesAsTheLimit(): void
    {
        self::assertSame([], $this->lines(array_fill(0, Invoice::MAX_LINES, $this->line())));
    }

    public function testADocumentWithOneLineOverTheLimitIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.too_many_lines')], $this->lines(array_fill(0, Invoice::MAX_LINES + 1, $this->line(text: ' '))));
    }

    public function testALineWithABlankTextIsRefusedByItsIndex(): void
    {
        self::assertEquals([new IssueProblem('lines.1.text', 'invoice.problem.line_text_missing')], $this->lines([$this->line(), $this->line(text: " \t")]));
    }

    public function testAUnitOfTheLongestLengthIsAccepted(): void
    {
        self::assertSame([], $this->lines([$this->line(unit: str_repeat('č', 32))]));
    }

    public function testAUnitOverTheLongestLengthIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines.0.unit', 'invoice.problem.too_long')], $this->lines([$this->line(unit: str_repeat('č', 33))]));
    }

    public function testALineInAnotherCurrencyIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines.0.price', 'invoice.problem.currency_not_home')], $this->lines([$this->line(currency: Currency::EUR)]));
    }

    public function testEveryProblemOfALineIsReportedInOrder(): void
    {
        self::assertEquals([
            new IssueProblem('lines.0.text', 'invoice.problem.line_text_missing'),
            new IssueProblem('lines.0.unit', 'invoice.problem.too_long'),
            new IssueProblem('lines.0.price', 'invoice.problem.currency_not_home'),
            new IssueProblem('lines.0.rate', 'invoice.problem.line_rate_missing'),
        ], $this->lines([$this->line(kind: null, text: '', unit: str_repeat('x', 33), currency: Currency::EUR)]));
    }

    public function testAPayerLineOutsideVatIsRefusedUnlessItDeducts(): void
    {
        self::assertEquals([new IssueProblem('lines.0.vat', 'invoice.problem.vat_invalid')], $this->lines([$this->line(treatment: TaxTreatment::NotApplicable, kind: null)]));
        self::assertSame([], $this->lines([$this->line(treatment: TaxTreatment::NotApplicable, kind: null, deducts: 'advance-1')]));
    }

    public function testANonPayerLineOutsideVatPasses(): void
    {
        self::assertSame([], $this->lines([$this->line(treatment: TaxTreatment::NotApplicable, kind: null)], VatRegime::NonPayer));
    }

    public function testAPayerLineWithoutARateIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines.0.rate', 'invoice.problem.line_rate_missing')], $this->lines([$this->line(kind: null)]));
    }

    public function testAPayerLineOfARateTheCountryNoLongerHasIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines.0.rate', 'invoice.problem.line_rate_missing')], $this->lines([$this->line(kind: VatRateKind::SecondReduced)], taxPoint: ExampleCountry::SECOND_REDUCED_ABOLISHED));
    }

    public function testAPayerLineOfARateTheCountryStillHadOnTheTaxPointPasses(): void
    {
        self::assertSame([], $this->lines([$this->line(kind: VatRateKind::SecondReduced)], taxPoint: '2026-11-14'));
    }

    public function testARateIsNotLookedUpWithoutATaxPoint(): void
    {
        self::assertSame([], $this->lines([$this->line(kind: VatRateKind::SecondReduced)], taxPoint: null));
        self::assertEquals([new IssueProblem('lines.0.rate', 'invoice.problem.line_rate_missing')], $this->lines([$this->line(kind: null)], taxPoint: null));
    }

    public function testAReducedRateTheCountryHasPasses(): void
    {
        self::assertSame([], $this->lines([$this->line(kind: VatRateKind::Reduced)]));
    }

    public function testANonPayerLineNeedsNoRate(): void
    {
        self::assertSame([], $this->lines([$this->line(kind: null)], VatRegime::NonPayer));
    }

    public function testAnExemptPayerLineNeedsNoRate(): void
    {
        self::assertSame([], $this->lines([$this->line(treatment: TaxTreatment::Exempt, kind: null)]));
    }

    public function testReverseChargeIsOnlyForAPayer(): void
    {
        self::assertEquals([new IssueProblem('lines.0.treatment', 'invoice.problem.reverse_charge_payer')], $this->lines([$this->reverseCharge()], VatRegime::NonPayer));
    }

    public function testReverseChargeNeedsAValidVatIdOfTheCustomer(): void
    {
        self::assertEquals([new IssueProblem('customer', 'invoice.problem.reverse_charge_customer_vat_id')], $this->lines([$this->reverseCharge()], customerVatId: null));
        self::assertEquals([new IssueProblem('customer', 'invoice.problem.reverse_charge_customer_vat_id')], $this->lines([$this->reverseCharge()], customerVatId: 'CZ12'));
    }

    public function testDomesticReverseChargeTakesNoCodeOrAKnownOne(): void
    {
        self::assertSame([], $this->lines([$this->reverseCharge()]));
        self::assertSame([], $this->lines([$this->reverseCharge('')]));
        self::assertSame([], $this->lines([$this->reverseCharge('1')]));
        self::assertSame([], $this->lines([$this->reverseCharge('3')]));
    }

    public function testDomesticReverseChargeRefusesAnUnknownCode(): void
    {
        self::assertEquals([new IssueProblem('lines.0.supplyCode', 'invoice.problem.supply_code_invalid')], $this->lines([$this->reverseCharge('9')]));
    }

    public function testAnUnknownCodeIsNotCheckedWithoutATaxPoint(): void
    {
        self::assertSame([], $this->lines([$this->reverseCharge('9')], taxPoint: null));
    }

    public function testForeignReverseChargeTakesNoCode(): void
    {
        self::assertSame([], $this->lines([$this->reverseCharge()], customerVatId: 'DE123456789'));
        self::assertSame([], $this->lines([$this->reverseCharge('')], customerVatId: 'DE123456789'));
        self::assertEquals([new IssueProblem('lines.0.supplyCode', 'invoice.problem.supply_code_not_allowed')], $this->lines([$this->reverseCharge('1')], customerVatId: 'DE123456789'));
    }

    public function testReverseChargeIsDomesticByTheCountryOfTheIssuer(): void
    {
        self::assertEquals([new IssueProblem('lines.0.supplyCode', 'invoice.problem.supply_code_not_allowed')], $this->lines([$this->reverseCharge('1')], issuerCountry: 'SK'));
        self::assertEquals([new IssueProblem('lines.0.supplyCode', 'invoice.problem.supply_code_invalid')], $this->lines([$this->reverseCharge('9')], customerVatId: 'SK1234567890', issuerCountry: 'SK'));
    }

    public function testAReverseChargeLineInAnotherCurrencyReportsBoth(): void
    {
        self::assertEquals([
            new IssueProblem('lines.0.price', 'invoice.problem.currency_not_home'),
            new IssueProblem('lines.0.supplyCode', 'invoice.problem.supply_code_invalid'),
        ], $this->lines([$this->line(treatment: TaxTreatment::ReverseCharge, kind: VatRateKind::Base, supplyCode: '9', currency: Currency::EUR)]));
    }

    public function testTheProblemsOfSeveralLinesAddUp(): void
    {
        self::assertEquals([
            new IssueProblem('lines.0.text', 'invoice.problem.line_text_missing'),
            new IssueProblem('lines.1.vat', 'invoice.problem.vat_invalid'),
            new IssueProblem('customer', 'invoice.problem.reverse_charge_customer_vat_id'),
        ], $this->lines([$this->line(text: ''), $this->line(treatment: TaxTreatment::NotApplicable, kind: null), $this->reverseCharge()], customerVatId: null));
    }

    public function testOrdinaryAmountsFitADocument(): void
    {
        self::assertSame([], $this->amounts([$this->line()]));
    }

    public function testTheLargestAmountFitsADocument(): void
    {
        self::assertSame([], $this->amounts([$this->line('999999999999.99', kind: null)], VatRegime::NonPayer));
        self::assertSame([], $this->amounts([$this->line('-999999999999.99', kind: null), $this->line('999999999999.99', kind: null)], VatRegime::NonPayer));
    }

    public function testAnAmountOverTheLargestIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('1000000000000.00', kind: null)], VatRegime::NonPayer));
    }

    public function testANegativeAmountOverTheLargestIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('-1000000000000.00', kind: null)], VatRegime::NonPayer));
    }

    public function testAUnitPriceOverTheLargestIsRefusedThoughTheLineFits(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('1500000000000.00', kind: null, quantity: '0.5')], VatRegime::NonPayer));
    }

    public function testALineOverTheLargestIsRefusedThoughTheTotalsFit(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('600000000000.00', kind: null, quantity: '2'), $this->line('-600000000000.00', kind: null, quantity: '2')], VatRegime::NonPayer));
    }

    public function testAGrossOfALineOverTheLargestIsRefusedThoughItsNetFits(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('900000000000.00'), $this->line('-900000000000.00')]));
    }

    public function testATotalOverTheLargestIsRefusedThoughEachLineFits(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('600000000000.00', kind: null), $this->line('600000000000.00', kind: null)], VatRegime::NonPayer));
    }

    public function testANetTotalOverTheLargestIsRefusedThoughTheGrossFits(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([
            $this->line('950000000000.00', TaxTreatment::Exempt, null),
            $this->line('950000000000.00', TaxTreatment::Exempt, null),
            $this->line('-800000000000.00'),
        ]));
    }

    public function testAnAmountThatOverflowsIsRefused(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.amount_out_of_range')], $this->amounts([$this->line('90000000000000000.00', kind: null, quantity: '999999999')], VatRegime::NonPayer));
    }

    public function testANegativeTotalIsRefusedOnAnInvoice(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.payable_negative')], $this->amounts([$this->line('-0.40')], rounding: TotalRounding::None));
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.payable_negative')], $this->rules()->amounts([$this->line('-0.40')], VatRegime::Payer, TotalRounding::None, new ExampleCountry(), new \DateTimeImmutable(self::TAX_POINT)));
    }

    public function testATotalRoundedToZeroIsNotNegative(): void
    {
        self::assertSame([], $this->amounts([$this->line('-0.40')], rounding: TotalRounding::WholeUnit));
    }

    public function testAZeroTotalIsAccepted(): void
    {
        self::assertSame([], $this->amounts([$this->line('0.00')], rounding: TotalRounding::None));
    }

    public function testACorrectionMayBeNegative(): void
    {
        self::assertSame([], $this->amounts([$this->line('-100.00')], kind: DocumentKind::CorrectionDocument));
    }

    public function testAnAdvanceMayNotBeNegative(): void
    {
        self::assertEquals([new IssueProblem('lines', 'invoice.problem.payable_negative')], $this->amounts([$this->line('-100.00')], kind: DocumentKind::Advance));
    }

    public function testDeductionsBeyondTheSupplyLeaveADifferenceToRefund(): void
    {
        self::assertSame([], $this->amounts([$this->line('100.00'), $this->line('-500.00', treatment: TaxTreatment::NotApplicable, kind: null, deducts: 'advance-1')]));
    }

    public function testAVatIdIsReadOrNull(): void
    {
        self::assertNull(LineRules::vatIdOf(null));
        self::assertNull(LineRules::vatIdOf('CZ12'));
        self::assertNull(LineRules::vatIdOf(''));
        self::assertEquals(VatId::fromString('CZ87654320'), LineRules::vatIdOf('cz 87654320'));
    }

    /**
     * @param list<LineInput> $lines
     *
     * @return list<IssueProblem>
     */
    private function lines(array $lines, VatRegime $regime = VatRegime::Payer, ?string $customerVatId = 'CZ87654320', string $issuerCountry = 'CZ', ?string $taxPoint = self::TAX_POINT): array
    {
        return $this->rules()->lines($lines, $regime, new ExampleCountry(), $customerVatId, $issuerCountry, null === $taxPoint ? null : new \DateTimeImmutable($taxPoint));
    }

    /**
     * @param list<LineInput> $lines
     *
     * @return list<IssueProblem>
     */
    private function amounts(array $lines, VatRegime $regime = VatRegime::Payer, TotalRounding $rounding = TotalRounding::None, DocumentKind $kind = DocumentKind::Invoice): array
    {
        return $this->rules()->amounts($lines, $regime, $rounding, new ExampleCountry(), new \DateTimeImmutable(self::TAX_POINT), $kind);
    }

    private function rules(): LineRules
    {
        $firms = self::createStub(LegalEntities::class);
        $firms->method('all')->willReturn([$this->firm]);

        return new LineRules(new LinePricing(new InvoiceCalculator()), new EmojiPrintableText(), new InstanceFirm($firms));
    }

    private function firmOf(VatStatus $status, ?string $vatId, string $register): LegalEntity
    {
        return new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null === $vatId ? null : VatId::fromString($vatId), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $status, $register);
    }

    private function reverseCharge(?string $supplyCode = null): LineInput
    {
        return $this->line(treatment: TaxTreatment::ReverseCharge, kind: VatRateKind::Base, supplyCode: $supplyCode);
    }

    private function line(
        string $price = '1000.00',
        TaxTreatment $treatment = TaxTreatment::Standard,
        ?VatRateKind $kind = VatRateKind::Base,
        ?string $supplyCode = null,
        string $text = 'Work',
        string $unit = 'h',
        Currency $currency = Currency::CZK,
        ?string $deducts = null,
        string $quantity = '1',
    ): LineInput {
        return new LineInput($text, Quantity::fromString($quantity), $unit, Money::of($price, $currency), PriceType::WithoutVat, $treatment, $kind, $supplyCode, Percent::fromString('0'), $deducts);
    }
}
