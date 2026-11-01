<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\IssueRules;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\NumberAllocator;
use App\Invoicing\Application\StoredInvoice;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\LineAmounts;
use App\Invoicing\Domain\LineValues;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice->id());

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
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('10', Currency::EUR), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

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
        } catch (IssueRefused $refused) {
            $fields = array_map(static fn (IssueProblem $problem): string => $problem->field, $refused->problems);
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
        $this->trash->trash($invoice->id(), 'Wrong customer');

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

            public function lock(string $id): Invoice
            {
                return $this->store->lock($id);
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
                throw new \RuntimeException('The disk is full.');
            }

            public function removeLine(InvoiceLine $line): void
            {
            }

            public function addAbandoned(AbandonedNumber $number): void
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

            public function idsForPeriod(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->store->idsForPeriod($firm, $from, $to);
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
            $container->get(FirmInvoicingSettings::class),
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

    public function testTheIssuerCopyTakesTheFirmsContact(): void
    {
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $row = $settings->for($this->firm);
        $row->change(TotalRounding::None, '', '', '', 'office@example.com', '+420 111 111 111', 'example.com');
        $settings->save($row);
        $this->em->flush();

        $invoice = $this->reloaded($this->issue->issue($this->input([$this->line('10.40')])));

        self::assertSame('office@example.com', $invoice->issuer()->email);
        self::assertSame('+420 111 111 111', $invoice->issuer()->phone);
        self::assertSame('example.com', $invoice->issuer()->web);
        self::assertSame(TotalRounding::None, $invoice->rounding());
        self::assertSame(0, $invoice->totalRounding()->minor());
    }

    public function testAnIssuerWithoutAnEmailHasNone(): void
    {
        self::assertNull($this->reloaded($this->issue->issue($this->input()))->issuer()->email);
    }

    public function testTheCustomerCopyTakesTheFirmsOwnEmail(): void
    {
        self::getContainer()->get(ContactSettingsForFirm::class)->changeEmails($this->customer, $this->firm, [new InvoicingEmail('firm-only@example.com', EmailPurpose::Invoices)]);
        $this->em->flush();

        self::assertSame('firm-only@example.com', $this->issue->issue($this->input())->customer()->email);
    }

    public function testACustomerWithOnlyAGeneralEmailGetsThatCopied(): void
    {
        $customer = new Party(PartyKind::Company, 'Plain Customer', new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em->persist($customer);
        $this->em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Street', 'City', '100 00', 'CZ')));
        $this->em->persist(new PartyEmail($customer, 'general@example.com', EmailPurpose::General));
        $this->em->flush();

        self::assertSame('general@example.com', $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()]))->customer()->email);
    }

    public function testAContactWithoutABillingAddressIsRefused(): void
    {
        $customer = new Party(PartyKind::Company, 'No Address', new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em->persist($customer);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_address_incomplete', fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()])));
    }

    public function testTheIbanIsDroppedWithoutATransferAndNormalisedWithOne(): void
    {
        $spaced = trim(chunk_split(strtolower(self::IBAN), 4, ' '));

        self::assertSame(self::IBAN, $this->issue->issue($this->input(overrides: ['bankAccount' => $spaced]))->bankAccount());
        self::assertNull($this->issue->issue($this->input(overrides: ['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => self::IBAN]))->bankAccount());
        self::assertNull($this->issue->issue($this->input(overrides: ['paymentMethod' => PaymentMethod::Card, 'bankAccount' => 'nonsense']))->bankAccount());
    }

    public function testTheCustomerCopyMustBeCompleteInEveryField(): void
    {
        foreach (['name' => ['  ', self::address()], 'street' => ['N', new Address(' ', 'C', '1', 'CZ')], 'city' => ['N', new Address('S', ' ', '1', 'CZ')], 'postal' => ['N', new Address('S', 'C', ' ', 'CZ')], 'country' => ['N', new Address('S', 'C', '1', ' ')]] as $case => [$name, $address]) {
            $copy = new DocumentParty($name, null, null, $address, '', null, '', '', null, null);
            self::assertSame(['invoice.problem.customer_address_incomplete'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerOnDocument' => $copy]))), $case);
        }
    }

    /**
     * @return iterable<string, array{DocumentParty}>
     */
    public static function oversizedCopies(): iterable
    {
        $party = static fn (string $name = 'N', ?string $number = null, ?string $vat = null, string $register = '', ?string $email = null, string $phone = '', string $web = '', ?string $logo = null, ?string $stamp = null, ?Address $address = null): DocumentParty => new DocumentParty($name, $number, $vat, $address ?? self::address(), $register, $email, $phone, $web, $logo, $stamp);
        yield 'name' => [$party(name: str_repeat('č', 256))];
        yield 'registration' => [$party(number: str_repeat('1', 65))];
        yield 'vat' => [$party(vat: 'CZ'.str_repeat('1', 13))];
        yield 'register' => [$party(register: str_repeat('r', 256))];
        yield 'email' => [$party(email: str_repeat('e', 255))];
        yield 'phone' => [$party(phone: str_repeat('1', 65))];
        yield 'web' => [$party(web: str_repeat('w', 256))];
        yield 'logo' => [$party(logo: str_repeat('l', 73))];
        yield 'stamp' => [$party(stamp: str_repeat('s', 73))];
        yield 'street' => [$party(address: new Address(str_repeat('s', 256), 'C', '1', 'CZ'))];
        yield 'city' => [$party(address: new Address('S', str_repeat('c', 129), '1', 'CZ'))];
        yield 'postal' => [$party(address: new Address('S', 'C', str_repeat('1', 33), 'CZ'))];
        yield 'country' => [$party(address: new Address('S', 'C', '1', 'CZE'))];
    }

    #[DataProvider('oversizedCopies')]
    public function testEveryFieldOfTheCopyMustFit(DocumentParty $copy): void
    {
        self::assertSame(['invoice.problem.too_long'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerOnDocument' => $copy]))));
    }

    public function testACopyAtEveryLimitFits(): void
    {
        $copy = new DocumentParty(str_repeat('č', 255), str_repeat('1', 64), null, new Address(str_repeat('s', 255), str_repeat('c', 128), str_repeat('1', 32), 'CZ'), str_repeat('r', 255), str_repeat('e', 254), str_repeat('1', 64), str_repeat('w', 255), str_repeat('l', 72), str_repeat('s', 72));

        self::assertSame(str_repeat('č', 255), $this->reloaded($this->issue->issue($this->input(overrides: ['customerOnDocument' => $copy])))->customer()->name);
    }

    public function testAnInvalidVatIdOnTheCopyIsRefused(): void
    {
        $copy = new DocumentParty('N', null, 'CZ1', self::address(), '', null, '', '', null, null);

        self::assertSame(['invoice.problem.customer_vat_id_invalid'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerOnDocument' => $copy]))));
    }

    public function testLimitsOfTheDocumentFieldsAreInclusive(): void
    {
        $line = new LineInput('Work', Quantity::fromString('1'), str_repeat('č', 32), Money::of('10', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $invoice = $this->issue->issue($this->input([$line], ['orderNumber' => str_repeat('č', 64), 'constantSymbol' => str_repeat('1', 10), 'typedNumber' => str_repeat('1', 80)]));

        self::assertSame(str_repeat('1', 80), $invoice->number());
        self::assertSame(['invoice.problem.too_long'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input([new LineInput('Work', Quantity::fromString('1'), str_repeat('č', 33), Money::of('10', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))]))));
    }

    public function testProblemsNameTheFieldAndTheLine(): void
    {
        $bad = new LineInput(' ', Quantity::fromString('1'), str_repeat('u', 33), Money::of('10', Currency::EUR), PriceType::WithoutVat, TaxTreatment::Standard, null, null, Percent::fromString('0'));
        $reverse = $this->line(treatment: TaxTreatment::ReverseCharge, kind: null);

        try {
            $this->issue->issue($this->input([$this->line(), $bad, $reverse], ['taxPointDate' => null]));
            self::fail('Expected a refusal.');
        } catch (IssueRefused $refused) {
            self::assertEquals([
                new IssueProblem('taxPointDate', 'invoice.problem.tax_point_missing'),
                new IssueProblem('lines.1.text', 'invoice.problem.line_text_missing'),
                new IssueProblem('lines.1.unit', 'invoice.problem.too_long'),
                new IssueProblem('lines.1.price', 'invoice.problem.currency_not_home'),
                new IssueProblem('lines.1.rate', 'invoice.problem.line_rate_missing'),
            ], $refused->problems);
        }
    }

    public function testProblemFieldsOfTheRest(): void
    {
        $fields = fn (InvoiceInput $input): array => $this->fieldsOf($input);
        $reverse = fn (?string $code): LineInput => $this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: $code);
        $withoutVat = $this->customer(null);
        $foreign = $this->customer('DE123456789');
        $nonPayer = $this->secondFirm(VatStatus::NotRegistered, null);
        $this->em->flush();

        self::assertSame(['lines.1.supplyCode'], $fields($this->input([$this->line(), $reverse('999')])));
        self::assertSame(['lines.0.supplyCode'], $fields($this->input([$reverse('999')])));
        self::assertSame(['lines.0.supplyCode'], $fields($this->input([$reverse('4')], ['customerId' => $foreign->id()])));
        self::assertSame(['customer'], $fields($this->input([$reverse('4')], ['customerId' => $withoutVat->id()])));
        self::assertSame(['lines.0.treatment'], $fields($this->input([$reverse('4')], ['firmId' => $nonPayer->id(), 'seriesId' => null])));
        self::assertSame(['lines.0.rate'], $fields($this->input([$this->line(kind: null)])));
        self::assertSame(['bankAccount'], $fields($this->input(overrides: ['bankAccount' => null])));
        self::assertSame(['orderNumber'], $fields($this->input(overrides: ['orderNumber' => str_repeat('1', 65)])));
        self::assertSame(['constantSymbol'], $fields($this->input(overrides: ['constantSymbol' => str_repeat('1', 11)])));
        self::assertSame(['number'], $fields($this->input(overrides: ['typedNumber' => '---'])));
        self::assertSame(['series'], $fields($this->input(overrides: ['seriesId' => '01963c5e-0000-7000-8000-000000000000'])));
        self::assertSame(['customer'], $fields($this->input(overrides: ['customerId' => '01963c5e-0000-7000-8000-000000000000'])));
        self::assertSame(['lines'], $fields($this->input([$this->line('-1.00')])));
        self::assertSame(['lines'], $fields($this->input([$this->line('92233720368547757.00')])));
    }

    public function testRulesOfAReverseChargeLineStillAskForTheTaxPoint(): void
    {
        self::assertSame(['invoice.problem.tax_point_missing'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '999')], ['taxPointDate' => null]))));
    }

    public function testAFirmIsNotRefusedTwiceForTheSameThing(): void
    {
        $firm = $this->secondFirm(VatStatus::NotRegistered, null, ' ');

        self::assertSame(['invoice.problem.firm_register_entry_missing'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null]))));
    }

    public function testATreatmentWithoutARateKeepsNoPriceType(): void
    {
        $invoice = $this->issue->issue($this->input([
            $this->line('121.00', PriceType::WithVat, TaxTreatment::ReverseCharge, supplyCode: '4'),
            $this->line('121.00', PriceType::WithVat, TaxTreatment::Exempt, VatRateKind::Base, '4'),
            $this->line('121.00', PriceType::BaseOnly),
        ]));
        $lines = $this->store->lines($this->reloaded($invoice));

        self::assertSame(PriceType::WithoutVat, $lines[0]->priceType());
        self::assertSame(12100, $lines[0]->net()->minor());
        self::assertSame(PriceType::WithoutVat, $lines[1]->priceType());
        self::assertNull($lines[1]->rate());
        self::assertNull($lines[1]->supplyCode());
        self::assertSame(PriceType::BaseOnly, $lines[2]->priceType());
        self::assertSame('21.00', $lines[2]->rate()?->decimal());
        self::assertSame(0, $lines[2]->vat()->minor());
    }

    public function testAnEmptySupplyCodeIsNoCode(): void
    {
        $customer = $this->customer('DE123456789');
        $this->em->flush();

        $invoice = $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '')], ['customerId' => $customer->id()]));

        self::assertNull($this->store->lines($this->reloaded($invoice))[0]->supplyCode());
        $this->assertRefusedFor('invoice.problem.supply_code_invalid', fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '')])));
    }

    public function testAProblemOfTheFirmStaysWhenItsCountryIsUnsupported(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, 'DE123456789', '', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));

        $keys = $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null])));

        self::assertSame(['invoice.problem.firm_register_entry_missing', 'invoice.problem.firm_country_unsupported'], $keys);
    }

    public function testEveryProblemOfTheFirmIsReported(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, null, ' ');
        $firm->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        self::assertSame(['invoice.problem.firm_archived', 'invoice.problem.firm_vat_id_missing', 'invoice.problem.firm_register_entry_missing'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['firmId' => $firm->id(), 'seriesId' => null]))));
    }

    public function testEveryProblemOfTheCustomerIsReported(): void
    {
        $customer = $this->customer(address: new Address('', 'City', '1', 'CZ'));
        $customer->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        self::assertSame(['invoice.problem.customer_archived', 'invoice.problem.customer_address_incomplete'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()]))));
    }

    public function testATakenNumberIsReportedBesideOtherProblems(): void
    {
        $this->issue->issue($this->input());

        self::assertSame(['invoice.problem.number_taken', 'invoice.problem.no_lines'], $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input([], ['typedNumber' => '20260001']))));
    }

    public function testATypedNumberIsMeasuredWithoutSpacesAndInCharacters(): void
    {
        $spaced = $this->issue->issue($this->input(overrides: ['typedNumber' => '  '.str_repeat('7', 80).'  ']));
        $accented = $this->issue->issue($this->input(overrides: ['typedNumber' => str_repeat('ČA', 40)]));

        self::assertSame(str_repeat('7', 80), $spaced->number());
        self::assertSame(str_repeat('ČA', 40), $accented->number());
    }

    public function testLinesWithoutARateNeedNoKind(): void
    {
        $nonPayer = $this->secondFirm(VatStatus::NotRegistered, null);

        $this->issue->issue($this->input([$this->line(kind: null)], ['firmId' => $nonPayer->id(), 'seriesId' => null]));
        $invoice = $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::Exempt, kind: null)]));

        self::assertSame(TaxTreatment::Exempt, $this->store->lines($this->reloaded($invoice))[0]->treatment());
    }

    public function testReverseChargeForAContactWithoutAnAddressIsOneProblem(): void
    {
        $customer = new Party(PartyKind::Company, 'No Address', new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em->persist($customer);
        $this->em->flush();

        self::assertContains('invoice.problem.reverse_charge_customer_vat_id', $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')], ['customerId' => $customer->id()]))));
    }

    public function testInvoicingEmailsComeBeforeTheGeneralOne(): void
    {
        $this->em->persist(new PartyEmail($this->customer, 'general@example.com', EmailPurpose::General));
        $this->em->flush();

        self::assertSame('billing@example.com', $this->issue->issue($this->input())->customer()->email);
    }

    public function testAPersonWithoutEmailOrNumberIsCopied(): void
    {
        $customer = new Party(PartyKind::Person, 'Plain Person', null, null, '');
        $this->em->persist($customer);
        $this->em->persist(new PartyAddress($customer, AddressType::Billing, self::address()));
        $this->em->flush();

        $copy = $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()]))->customer();

        self::assertNull($copy->email);
        self::assertNull($copy->registrationNumber);
    }

    public function testARefusalNamesItsProblems(): void
    {
        $refused = new IssueRefused([new IssueProblem('a', 'first'), new IssueProblem('b', 'second')]);

        self::assertSame('The document cannot be saved: first, second', $refused->getMessage());
    }

    public function testASeriesOfAnotherFirmIsRefusedByTheAggregate(): void
    {
        $other = $this->secondFirm(VatStatus::Registered, 'CZ27074358');
        $series = $this->seriesOf($other);
        $change = $this->input()->change($this->customer, DocumentParty::ofCustomer($this->customer, self::address(), null));

        $this->expectExceptionMessageIsOrContains('A document takes a series of its own firm.');
        new Invoice($this->firm, $series, '1', VatRegime::Payer, TotalRounding::None, Currency::CZK, DocumentParty::ofCustomer($this->customer, self::address(), null), $change, null, new \DateTimeImmutable('2026-11-01'));
    }

    public function testAnInputWithoutDatesGivesNoChange(): void
    {
        $copy = DocumentParty::ofCustomer($this->customer, self::address(), null);

        foreach (['taxPointDate', 'dueDate'] as $date) {
            try {
                $this->input(overrides: [$date => null])->change($this->customer, $copy);
                self::fail('Expected the guard.');
            } catch (\LogicException $guard) {
                self::assertStringContainsString('IssueRules refuses', $guard->getMessage());
            }
        }
    }

    public function testAnEntityHasNoTenantBeforeItIsStored(): void
    {
        $firm = $this->secondFirm(VatStatus::Registered, 'CZ27074358');
        $series = new Series($firm, 'Unsaved', SeriesFormat::fromString('A{NNN}'), DocumentKind::Invoice);
        $copy = DocumentParty::ofCustomer($this->customer, self::address(), null);
        $invoice = new Invoice($firm, $series, 'A1', VatRegime::Payer, TotalRounding::None, Currency::CZK, $copy, $this->input()->change($this->customer, $copy), null, new \DateTimeImmutable('2026-11-01'));
        $zero = Money::ofMinor(0, Currency::CZK);
        $values = new LineValues($zero, Quantity::fromString('1'), PriceType::WithoutVat, TaxTreatment::Exempt, null, Percent::fromString('0'));
        $line = new InvoiceLine($invoice, 0, 'x', 'h', null, null, $values, new LineAmounts($zero, $zero, $zero, $zero));

        foreach ([$series, $invoice, $line, new AbandonedNumber($invoice, 'A0'), FirmInvoicing::standard($firm)] as $entity) {
            try {
                $entity->tenantId();
                self::fail('Expected the guard.');
            } catch (\LogicException $guard) {
                self::assertSame('The tenant is stamped on persist.', $guard->getMessage());
            }
        }
    }

    public function testLineTextIsTrimmed(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line(text: '  Work ')]));

        self::assertSame('Work', $this->store->lines($this->reloaded($invoice))[0]->text());
    }

    private function secondFirm(VatStatus $status, ?string $vatId, string $register = 'Example register, entry C 2', ?Address $address = null): LegalEntity
    {
        $firm = new LegalEntity('Second Firm s.r.o.', new RegistrationNumber('CZ', '27074358'), null === $vatId ? null : \App\Party\Domain\VatId::fromString($vatId), $address ?? new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $status, $register, [self::IBAN]);
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }

    public function testASymbolFollowingTheNumberTakesTheRealOne(): void
    {
        $this->issue->issue($this->input());

        $invoice = $this->issue->issue($this->input(overrides: ['variableSymbol' => '20260001', 'variableSymbolFollowsNumber' => true]));

        self::assertSame('20260002', $invoice->number());
        self::assertSame('20260002', $invoice->variableSymbol());
    }

    public function testASymbolThatDoesNotFollowStays(): void
    {
        $this->issue->issue($this->input());

        self::assertSame('20260001', $this->issue->issue($this->input())->variableSymbol());
    }

    public function testAConstantSymbolHasDigitsOnly(): void
    {
        $this->assertRefusedFor('invoice.problem.constant_symbol_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['constantSymbol' => '03a8'])));
    }

    public function testAnEmptyConstantSymbolIsFine(): void
    {
        self::assertSame('', $this->issue->issue($this->input(overrides: ['constantSymbol' => '']))->constantSymbol());
    }

    public function testAPayerHasNoLineOutsideVat(): void
    {
        $this->assertRefusedFor('invoice.problem.vat_invalid', fn (): Invoice => $this->issue->issue($this->input([$this->line(treatment: TaxTreatment::NotApplicable, kind: null)])));
    }

    /**
     * @return list<string>
     */
    private function fieldsOf(InvoiceInput $input): array
    {
        try {
            $this->issue->issue($input);
        } catch (IssueRefused $refused) {
            return array_map(static fn (IssueProblem $problem): string => $problem->field, $refused->problems);
        }
        self::fail('Expected a refusal.');
    }

    private static function address(): Address
    {
        return new Address('Street 1', 'City', '100 00', 'CZ');
    }
}
