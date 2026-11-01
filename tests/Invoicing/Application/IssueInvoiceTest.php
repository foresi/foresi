<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueRules;
use App\Invoicing\Application\NumberAllocator;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class IssueInvoiceTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    public function testSavingIssuesWithANumberAndFrozenCopies(): void
    {
        $invoice = $this->reloaded($this->issue->issue($this->input()));

        self::assertSame('20260001', $invoice->number());
        self::assertSame('20260001', $invoice->numberKey());
        self::assertSame(VatRegime::Payer, $invoice->vatRegime());
        self::assertSame(TotalRounding::WholeUnit, $invoice->rounding());
        self::assertSame('1', $invoice->exchangeRate());
        self::assertSame(Currency::CZK, $invoice->currency());
        self::assertSame('Example Customer a.s.', $invoice->customer()->name);
        self::assertSame('CZ87654320', $invoice->customer()->vatId);
        self::assertSame('87654320', $invoice->customer()->registrationNumber);
        self::assertSame('Customer Street 2', $invoice->customer()->address->street);
        self::assertSame('billing@example.com', $invoice->customer()->email);
        self::assertSame('Example Firm s.r.o.', $invoice->issuer()->name);
        self::assertSame('CZ12345695', $invoice->issuer()->vatId);
        self::assertSame('Example register, entry C 1', $invoice->issuer()->registerEntry);
        self::assertSame('Example City', $invoice->issuer()->address->city);
        self::assertSame(100000, $invoice->net()->minor());
        self::assertSame(21000, $invoice->vat()->minor());
        self::assertSame(121000, $invoice->gross()->minor());
        self::assertSame(0, $invoice->totalRounding()->minor());
        self::assertSame(121000, $invoice->payable()->minor());
        self::assertSame('2026-11-15', $invoice->dueDate()->format('Y-m-d'));
        self::assertSame(self::IBAN, $invoice->bankAccount());
        self::assertSame('20260001', $invoice->variableSymbol());
        self::assertSame('0308', $invoice->constantSymbol());
        self::assertFalse($invoice->isDelivered());
        self::assertFalse($invoice->isTrashed());
    }

    public function testADefaultSeriesIsCreatedOnTheFirstIssue(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, 'CZ27074358');

        $invoice = $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null]));

        self::assertSame('20260001', $invoice->number());
        self::assertTrue($invoice->series()->isDefault());
        self::assertSame($firm, $invoice->series()->firm());
    }

    public function testAPayerInvoiceStoresKindAndValueOfTheRate(): void
    {
        $invoice = $this->issue->issue($this->input([
            $this->line('1000.00'),
            $this->line('121.00', PriceType::WithVat, kind: VatRateKind::Reduced),
        ]));

        $lines = $this->store->lines($this->reloaded($invoice));

        self::assertSame(VatRateKind::Base, $lines[0]->rateKind());
        self::assertSame('21.00', $lines[0]->rate()?->decimal());
        self::assertSame(VatRateKind::Reduced, $lines[1]->rateKind());
        self::assertSame('12.00', $lines[1]->rate()?->decimal());
        self::assertSame(10804, $lines[1]->net()->minor());
        self::assertSame(1296, $lines[1]->vat()->minor());
        self::assertSame(1, $lines[1]->position());
        self::assertSame('Work', $lines[1]->text());
        self::assertSame('h', $lines[1]->unit());
    }

    public function testARatedLineNeedsARateKindForAPayer(): void
    {
        $this->assertRefusedFor('invoice.problem.line_rate_missing', fn (): Invoice => $this->issue->issue($this->input([$this->line(kind: null)])));
    }

    public function testARatedLineNeedsARateOnTheTaxPoint(): void
    {
        $this->assertRefusedFor('invoice.problem.line_rate_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['taxPointDate' => new \DateTimeImmutable('2023-12-31')])));
    }

    public function testTheRateFollowsTheCalendarDateOfTheTaxPoint(): void
    {
        // Late on New Year's Eve in Prague is already 2024 in UTC+14, and the other way round: the calendar date as written counts.
        $invoice = $this->issue->issue($this->input(overrides: ['taxPointDate' => new \DateTimeImmutable('2024-01-01 00:30:00', new \DateTimeZone('Pacific/Kiritimati'))]));

        self::assertSame('2024-01-01', $this->reloaded($invoice)->taxPointDate()->format('Y-m-d'));
    }

    public function testANonPayerInvoiceHasNoVatAndNoRates(): void
    {
        $firm = $this->secondFirm(VatStatus::NotRegistered, null);

        $invoice = $this->issue->issue($this->input([$this->line('121.00', PriceType::WithVat)], ['firmId' => $firm->id(), 'seriesId' => null]));
        $invoice = $this->reloaded($invoice);
        $lines = $this->store->lines($invoice);
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice);

        self::assertSame(VatRegime::NonPayer, $invoice->vatRegime());
        self::assertSame(12100, $invoice->net()->minor());
        self::assertSame(0, $invoice->vat()->minor());
        self::assertSame(TaxTreatment::NotApplicable, $lines[0]->treatment());
        self::assertSame(PriceType::WithoutVat, $lines[0]->priceType());
        self::assertNull($lines[0]->rateKind());
        self::assertNull($lines[0]->rate());
        self::assertSame(12100, $lines[0]->net()->minor());
        self::assertSame(0, $lines[0]->vat()->minor());
        self::assertSame(12100, $lines[0]->gross()->minor());
        self::assertCount(1, $document->summaries);
        self::assertTrue($document->summaries[0]->base->equals($lines[0]->net()));
        self::assertTrue($document->summaries[0]->vat->equals($lines[0]->vat()));
        self::assertTrue($document->summaries[0]->gross->equals($lines[0]->gross()));
        self::assertNull($document->summaries[0]->rate);
    }

    public function testAnIdentifiedPersonIssuesWithoutVat(): void
    {
        $firm = $this->secondFirm(VatStatus::IdentifiedPerson, 'CZ27074358');

        $invoice = $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null]));

        self::assertSame(VatRegime::NonPayer, $invoice->vatRegime());
    }

    public function testARegisteredFirmWithoutAVatIdIsRefusedAtIssue(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, null);

        $this->assertRefusedFor('invoice.problem.firm_vat_id_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null])));
    }

    public function testAFirmNeedsARegisterEntry(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, 'CZ27074358', '');

        $this->assertRefusedFor('invoice.problem.firm_register_entry_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null])));
    }

    public function testAnArchivedFirmIssuesNothing(): void
    {
        $this->firm->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.firm_archived', fn (): Invoice => $this->issue->issue($this->input()));
    }

    public function testAFirmInAnUnsupportedCountryIssuesNothing(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, 'DE123456789', 'Register', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));

        $this->assertRefusedFor('invoice.problem.firm_country_unsupported', fn (): Invoice => $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null])));
    }

    public function testAContactArchivedForTheFirmIsRefused(): void
    {
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->customer, $this->firm, true);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_archived', fn (): Invoice => $this->issue->issue($this->input()));
    }

    public function testAnUnknownCustomerIsRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.customer_unknown', fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => '01963c5e-0000-7000-8000-000000000000'])));
    }

    public function testASeriesOfAnotherFirmIsRefused(): void
    {
        $other = $this->secondFirm(VatStatus::Registered, 'CZ27074358');
        $series = $this->seriesOf($other);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.series_of_another_firm', fn (): Invoice => $this->issue->issue($this->input(overrides: ['seriesId' => $series->id()])));
    }

    public function testAnArchivedSeriesIsRefused(): void
    {
        $this->series->archive();
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.series_archived', fn (): Invoice => $this->issue->issue($this->input()));
    }

    public function testAtLeastOneLine(): void
    {
        $this->assertRefusedFor('invoice.problem.no_lines', fn (): Invoice => $this->issue->issue($this->input([])));
    }

    public function testEachLineHasText(): void
    {
        $this->assertRefusedFor('invoice.problem.line_text_missing', fn (): Invoice => $this->issue->issue($this->input([$this->line(text: '  ')])));
    }

    public function testABankTransferNeedsAnIbanOfTheFirm(): void
    {
        $this->assertRefusedFor('invoice.problem.bank_account_foreign', fn (): Invoice => $this->issue->issue($this->input(overrides: ['bankAccount' => 'CZ5508000000001234567899'])));
        $this->assertRefusedFor('invoice.problem.bank_account_foreign', fn (): Invoice => $this->issue->issue($this->input(overrides: ['bankAccount' => null])));
        $this->assertRefusedFor('invoice.problem.bank_account_foreign', fn (): Invoice => $this->issue->issue($this->input(overrides: ['bankAccount' => 'nonsense'])));
    }

    public function testCashNeedsNoIban(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null]));

        self::assertNull($invoice->bankAccount());
    }

    public function testTheCustomerAddressMustBeComplete(): void
    {
        $customer = $this->customer(address: new Address('', 'Customer City', '200 00', 'CZ'));
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_address_incomplete', fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()])));
    }

    public function testACopyTypedOnTheDocumentIsWhatGetsFrozen(): void
    {
        $copy = new DocumentParty('Typed Name', null, null, new Address('Typed 1', 'Typed City', '300 00', 'CZ'), '', null, '', '', null, null);

        $invoice = $this->issue->issue($this->input(overrides: ['customerOnDocument' => $copy]));

        self::assertSame('Typed Name', $this->reloaded($invoice)->customer()->name);
    }

    public function testReverseChargeNeedsTheCustomerVatId(): void
    {
        $customer = $this->customer(null);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.reverse_charge_customer_vat_id', fn (): Invoice => $this->issue->issue($this->input(
            [$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '1')],
            ['customerId' => $customer->id()],
        )));
    }

    public function testReverseChargeNeedsAPayerIssuer(): void
    {
        $firm = $this->secondFirm(VatStatus::NotRegistered, null);

        $this->assertRefusedFor('invoice.problem.reverse_charge_payer', fn (): Invoice => $this->issue->issue($this->input(
            [$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '1')],
            ['firmId' => $firm->id(), 'seriesId' => null],
        )));
    }

    public function testDomesticReverseChargeNeedsASupplyCode(): void
    {
        $this->assertRefusedFor('invoice.problem.supply_code_invalid', fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null)])));
        $this->assertRefusedFor('invoice.problem.supply_code_invalid', fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '999')])));

        $invoice = $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: VatRateKind::Base, supplyCode: '4')]));
        $line = $this->store->lines($this->reloaded($invoice))[0];

        self::assertSame(TaxTreatment::ReverseCharge, $line->treatment());
        self::assertSame('4', $line->supplyCode());
        self::assertNull($line->rate(), 'reverse charge shows no rate');
        self::assertNull($line->rateKind());
        self::assertSame(0, $line->vat()->minor());
    }

    public function testCrossBorderReverseChargeTakesNoSupplyCode(): void
    {
        $customer = $this->customer('DE123456789', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));
        $this->em->flush();
        $overrides = ['customerId' => $customer->id()];

        $this->assertRefusedFor('invoice.problem.supply_code_not_allowed', fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')], $overrides)));

        $invoice = $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null)], $overrides));

        self::assertNull($this->store->lines($this->reloaded($invoice))[0]->supplyCode());
    }

    public function testASupplyCodeOnlyStaysOnReverseCharge(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line(supplyCode: '4')]));

        self::assertNull($this->store->lines($this->reloaded($invoice))[0]->supplyCode());
    }

    public function testThePayableMustNotBeNegative(): void
    {
        $this->assertRefusedFor('invoice.problem.payable_negative', fn (): Invoice => $this->issue->issue($this->input([$this->line('-100.00')])));
    }

    public function testRoundingThatBringsThePayableToZeroIsAllowed(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('-0.30', kind: VatRateKind::Base)]));

        self::assertSame(-36, $invoice->gross()->minor());
        self::assertSame(0, $invoice->payable()->minor());
    }

    public function testAnAmountOutOfRangeIsRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.amount_out_of_range', fn (): Invoice => $this->issue->issue($this->input([$this->line('92233720368547757.00'), $this->line('92233720368547757.00')])));
    }

    public function testTheCurrencyIsTheHomeCurrency(): void
    {
        $line = new \App\Invoicing\Application\LineInput('Work', \App\Invoicing\Domain\Quantity::fromString('1'), 'h', Money::of('10', Currency::EUR), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, \App\Invoicing\Domain\Percent::fromString('0'));

        $this->assertRefusedFor('invoice.problem.currency_not_home', fn (): Invoice => $this->issue->issue($this->input([$line])));
    }

    public function testTaxPointAndDueDateMustBePresent(): void
    {
        $this->assertRefusedFor('invoice.problem.tax_point_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['taxPointDate' => null])));
        $this->assertRefusedFor('invoice.problem.due_date_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['dueDate' => null])));
    }

    public function testTheVariableSymbolIsEmptyOrUpToTenDigits(): void
    {
        $this->assertRefusedFor('invoice.problem.variable_symbol_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['variableSymbol' => '12345678901'])));
        $this->assertRefusedFor('invoice.problem.variable_symbol_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['variableSymbol' => '12A'])));
        $this->assertRefusedFor('invoice.problem.variable_symbol_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['variableSymbol' => "123\n"])));

        self::assertSame('', $this->issue->issue($this->input(overrides: ['variableSymbol' => '']))->variableSymbol());
        self::assertSame('1234567890', $this->issue->issue($this->input(overrides: ['variableSymbol' => '1234567890']))->variableSymbol());
    }

    public function testValuesThatDoNotFitTheirColumnsAreRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.too_long', fn (): Invoice => $this->issue->issue($this->input(overrides: ['orderNumber' => str_repeat('1', 65)])));
        $this->assertRefusedFor('invoice.problem.too_long', fn (): Invoice => $this->issue->issue($this->input(overrides: ['constantSymbol' => str_repeat('1', 11)])));
    }

    public function testEveryProblemIsReportedAtOnceWithItsField(): void
    {
        try {
            $this->issue->issue($this->input([], ['dueDate' => null, 'variableSymbol' => 'x']));
            self::fail('Expected a refusal.');
        } catch (\App\Invoicing\Application\IssueRefused $refused) {
            $fields = array_map(static fn (\App\Invoicing\Application\IssueProblem $problem): string => $problem->field, $refused->problems);
            self::assertSame(['dueDate', 'lines', 'variableSymbol'], [...array_intersect(['dueDate', 'lines', 'variableSymbol'], $fields)]);
        }
    }

    public function testATypedNumberAheadMovesTheCounter(): void
    {
        $typed = $this->issue->issue($this->input(overrides: ['typedNumber' => '20260050']));
        $next = $this->issue->issue($this->input());

        self::assertSame('20260050', $typed->number());
        self::assertSame('20260051', $next->number());
    }

    public function testATypedNumberBehindLeavesTheCounter(): void
    {
        $this->issue->issue($this->input(overrides: ['typedNumber' => '20260050']));
        $this->issue->issue($this->input(overrides: ['typedNumber' => '20260007']));

        self::assertSame('20260051', $this->issue->issue($this->input())->number());
    }

    public function testATypedNumberOfAnotherFormLeavesTheCounter(): void
    {
        $typed = $this->issue->issue($this->input(overrides: ['typedNumber' => ' IMPORT-77 ']));

        self::assertSame('IMPORT-77', $typed->number());
        self::assertSame('IMPORT77', $typed->numberKey());
        self::assertSame('20260001', $this->issue->issue($this->input())->number());
    }

    public function testATypedNumberDifferingOnlyInPunctuationIsRefused(): void
    {
        $this->issue->issue($this->input());

        $this->assertRefusedFor('invoice.problem.number_taken', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '2026-0001'])));
    }

    public function testATypedNumberNeedsLettersOrDigits(): void
    {
        $this->assertRefusedFor('invoice.problem.number_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '---'])));
        $this->assertRefusedFor('invoice.problem.number_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => str_repeat('1', 81)])));
    }

    public function testATypedNumberTakenAfterTheRulesLookedIsRefused(): void
    {
        $this->issue->issue($this->input(overrides: ['typedNumber' => 'TAKEN-1']));
        $allocator = self::getContainer()->get(NumberAllocator::class);

        $this->assertRefusedFor('invoice.problem.number_taken', fn () => $allocator->allocate($this->series, $this->firm, new \DateTimeImmutable('2026-11-01'), 'TAKEN-1', null));
    }

    public function testANumberAboveTheCounterRangeIsRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.number_out_of_range', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '20262147483648'])));
        self::assertSame('20260001', $this->counter->peek($this->series, new \DateTimeImmutable('2026-11-01')));
        self::assertSame('2147483647', (string) $this->issue->issue($this->input(overrides: ['typedNumber' => '20262147483647']))->series()->format()->sequenceIn('20262147483647', new \DateTimeImmutable('2026-11-01')));
    }

    public function testANumberOfAnotherYearIsNotOutOfRange(): void
    {
        self::assertSame('20252147483648', $this->issue->issue($this->input(overrides: ['typedNumber' => '20252147483648']))->number());
    }

    public function testANumberInTheTrashStaysTaken(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice, 'Wrong customer');

        $this->assertRefusedFor('invoice.problem.number_taken', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '20260001'])));
    }

    public function testAnAllocatedNumberTakenByHandIsSkipped(): void
    {
        $other = new Series($this->firm, 'Second', SeriesFormat::fromString('{YYYY}/{NNNN}'), DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();
        // The same key as the first two numbers of the standard series, typed in a form its counter cannot see.
        $this->issue->issue($this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => '2026/0001']));
        $this->issue->issue($this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => '2026/0002']));
        $this->em->clear();

        $invoice = $this->issue->issue($this->input());

        self::assertSame('20260003', $invoice->number());
    }

    public function testASeriesFullOfTakenNumbersIsRefused(): void
    {
        $other = new Series($this->firm, 'Second', SeriesFormat::fromString('{YYYY}/{NNNN}'), DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();
        for ($sequence = 1; $sequence <= 50; ++$sequence) {
            $this->issue->issue($this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => \sprintf('2026/%04d', $sequence)]));
        }
        $this->em->clear();

        $this->assertRefusedFor('invoice.problem.numbers_exhausted', fn (): Invoice => $this->issue->issue($this->input()));
        self::assertSame('20260001', $this->counter->peek($this->catalog->get($this->series->id()), new \DateTimeImmutable('2026-11-01')), 'the refusal returns the numbers');
    }

    public function testTheFiftiethTryStillGivesANumber(): void
    {
        $other = new Series($this->firm, 'Second', SeriesFormat::fromString('{YYYY}/{NNNN}'), DocumentKind::Invoice);
        $this->catalog->add($other);
        $this->em->flush();
        for ($sequence = 1; $sequence <= 49; ++$sequence) {
            $this->issue->issue($this->input(overrides: ['seriesId' => $other->id(), 'typedNumber' => \sprintf('2026/%04d', $sequence)]));
        }
        $this->em->clear();

        self::assertSame('20260050', $this->issue->issue($this->input())->number());
    }

    public function testASeriesOutOfNumbersIsRefused(): void
    {
        $this->series->startAt(2147483647, '2026');
        $this->em->flush();
        $this->issue->issue($this->input());
        $this->em->clear();

        $this->assertRefusedFor('invoice.problem.number_out_of_range', fn (): Invoice => $this->issue->issue($this->input()));
    }

    public function testTheIssueYearComesFromTheIssueDate(): void
    {
        self::mockTime('2027-01-02 09:00:00');

        $invoice = $this->issue->issue($this->input(overrides: ['issueDate' => new \DateTimeImmutable('2026-12-31'), 'taxPointDate' => new \DateTimeImmutable('2026-12-31')]));

        self::assertSame('20260001', $invoice->number());
        self::assertSame('2026-12-31', $this->reloaded($invoice)->issueDate()->format('Y-m-d'));
        self::assertSame('2027-01-02', $this->reloaded($invoice)->createdAt()->format('Y-m-d'));
    }

    public function testAFailedIssueConsumesNoNumber(): void
    {
        $date = new \DateTimeImmutable('2026-11-01');

        $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input([])));
        self::assertSame('20260001', $this->counter->peek($this->series, $date));

        $container = self::getContainer();
        $failing = new readonly class($this->store) implements InvoiceStore {
            public function __construct(private InvoiceStore $store)
            {
            }

            public function get(string $id): Invoice
            {
                return $this->store->get($id);
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
                throw new \RuntimeException('The disk is full.');
            }

            public function removeLine(InvoiceLine $line): void
            {
            }

            public function addAbandoned(\App\Invoicing\Domain\AbandonedNumber $number): void
            {
            }

            public function numberTaken(LegalEntity $firm, string $key, ?Invoice $except): bool
            {
                return $this->store->numberTaken($firm, $key, $except);
            }

            public function page(\App\Invoicing\Application\InvoiceFilter $filter): \App\Invoicing\Application\InvoicePage
            {
                return $this->store->page($filter);
            }

            public function lastFirmOf(string $userId): ?LegalEntity
            {
                return null;
            }
        };
        $issue = new IssueInvoice(
            $container->get(\App\Party\Application\LegalEntities::class),
            $this->catalog,
            $container->get(\App\Party\Application\PartyQuery::class),
            $container->get(IssueRules::class),
            $container->get(\App\Invoicing\Application\CustomerCopies::class),
            $container->get(\App\Invoicing\Application\LinePricing::class),
            $container->get(\App\Invoicing\Application\InvoicingCountries::class),
            $container->get(\App\Invoicing\Application\FirmInvoicingSettings::class),
            $failing,
            new NumberAllocator($this->counter, $failing),
            $container->get(\App\Party\Application\TransactionalWork::class),
            $container->get(\App\Invoicing\Application\ConflictRetry::class),
            $container->get(\App\Audit\Application\AuditActor::class),
            $container->get(\Psr\Clock\ClockInterface::class),
        );

        try {
            $issue->issue($this->input());
            self::fail('Expected the failure to travel on.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The disk is full.', $failure->getMessage());
        }

        $series = $this->catalog->get($this->series->id());
        self::assertSame('20260001', $this->counter->peek($series, $date));
    }

    public function testTwoIssuesFromOnePeekGetDifferentNumbers(): void
    {
        $peeked = $this->counter->peek($this->series, new \DateTimeImmutable('2026-11-01'));

        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());

        self::assertSame($peeked, $first->number());
        self::assertSame('20260002', $second->number(), 'the second is told the number it really got');
    }

    public function testASecondIssueReusesTheStoredDefaultSeries(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, 'CZ27074358');

        $first = $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null]));
        $second = $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null]));

        self::assertSame($first->series()->id(), $second->series()->id());
    }

    public function testTheFirmsRoundingAndCreatorAreRecorded(): void
    {
        self::mockTime('2026-11-01 10:00:00');
        $invoice = $this->issue->issue($this->input([$this->line('10.40')]));

        self::assertSame(1258, $invoice->gross()->minor());
        self::assertSame(42, $invoice->totalRounding()->minor());
        self::assertSame(1300, $invoice->payable()->minor());
        self::assertNull($invoice->createdBy(), 'nobody is signed in here');
        self::assertSame('2026-11-01', $invoice->createdAt()->format('Y-m-d'));
    }

    private function secondFirm(VatStatus $status, ?string $vatId, string $register = 'Example register, entry C 2', ?Address $address = null): LegalEntity
    {
        $firm = new LegalEntity('Second Firm s.r.o.', new \App\Party\Domain\RegistrationNumber('CZ', '27074358'), null === $vatId ? null : \App\Party\Domain\VatId::fromString($vatId), $address ?? new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $status, $register, [self::IBAN]);
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }
}
