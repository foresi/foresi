<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\AdvanceDocuments;
use Foresi\Invoicing\Application\AdvanceTaxation;
use Foresi\Invoicing\Application\CorrectionInput;
use Foresi\Invoicing\Application\CorrectionRules;
use Foresi\Invoicing\Application\CustomerCopies;
use Foresi\Invoicing\Application\DeductibleAdvances;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\ImportedDocumentInput;
use Foresi\Invoicing\Application\ImportedTotals;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRules;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\LinePricing;
use Foresi\Invoicing\Application\LineRules;
use Foresi\Invoicing\Application\PaymentTaxDocumentTexts;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Application\UnknownSeries;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\CorrectionType;
use Foresi\Invoicing\Domain\DocumentParties;
use Foresi\Invoicing\Domain\FirmInvoicing;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceCalculator;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rule a document must meet to be saved, asked one by one without a container:
 * a payer firm in CZ with one account, the standard series of each kind and a domestic
 * customer, all held in memory. A change to a rule fails here first and fast.
 */
final class IssueRulesTest extends TestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string OTHER_IBAN = 'DE89370400440532013000';

    private LegalEntity $firm;
    private FirmInvoicing $settings;
    private Series $series;
    private Series $advances;
    private Series $taxDocuments;
    private Series $corrections;
    private Party $customer;

    /** @var list<Series> */
    private array $every = [];

    /** @var array<string, Series> by kind */
    private array $defaults = [];

    /** @var array<string, Party> */
    private array $parties = [];

    /** @var array<string, PartyAddress> by party */
    private array $billing = [];

    /** @var array<string, Invoice> */
    private array $invoices = [];

    /** @var array<string, list<InvoiceLine>> by invoice */
    private array $lines = [];

    /** @var list<string> number keys */
    private array $taken = [];

    /** @var list<string> */
    private array $ibans = [self::IBAN];

    /** @var array<string, list<string>> tax documents on offer, by the invoice edited ('' for a new one) */
    private array $offered = [];

    private bool $goneOut = false;
    private bool $queued = false;

    protected function setUp(): void
    {
        $this->firm = $this->firmOf();
        $this->settings = FirmInvoicing::standard();
        $this->series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->advances = new Series('Advances', SeriesFormat::fromString('Z9{YYYY}{NNNN}'), DocumentKind::Advance);
        $this->taxDocuments = new Series('Tax documents', SeriesFormat::fromString('D8{YYYY}{NNNN}'), DocumentKind::PaymentTaxDocument);
        $this->corrections = new Series('Corrections', SeriesFormat::fromString('O7{YYYY}{NNNN}'), DocumentKind::CorrectionDocument);
        $this->every = [$this->series, $this->advances, $this->taxDocuments, $this->corrections];
        foreach ($this->every as $series) {
            $this->defaults[$series->kind()->value] = $series;
        }
        $this->customer = $this->party('Example Customer a.s.');
    }

    public function testAnOrdinaryInvoicePasses(): void
    {
        self::assertSame([], $this->check($this->input()));
    }

    public function testAnEditOfAnOrdinaryInvoicePasses(): void
    {
        self::assertSame([], $this->check($this->input(), $this->invoice()));
    }

    public function testAnOrdinaryImportPasses(): void
    {
        self::assertSame([], $this->import($this->imported()));
    }

    public function testANewDocumentOfAFirmThatMayNotIssueIsRefused(): void
    {
        $this->firm = $this->firmOf(register: '');

        self::assertSame(['firm invoice.problem.firm_register_entry_missing'], $this->keys($this->check($this->input())));
    }

    public function testAnEditIsNotHeldToTheFirmOfToday(): void
    {
        $existing = $this->invoice();
        $this->firm = $this->firmOf(register: '');

        self::assertSame([], $this->check($this->input(), $existing));
    }

    public function testAnImportIsNotHeldToTheFirmOfToday(): void
    {
        $issuer = $this->issuer();
        $this->firm = $this->firmOf(register: '');

        self::assertSame([], $this->import($this->imported(issuer: $issuer)));
    }

    public function testAnImportedPayerDocumentWithAnInvalidVatIdOfTheIssuerIsRefused(): void
    {
        self::assertSame(['firm invoice.problem.firm_vat_id_missing'], $this->keys($this->import($this->imported(issuer: $this->issuer(vatId: 'CZ12')))));
    }

    public function testAnImportedPayerDocumentIssuedWithoutAVatIdIsKeptAsIssued(): void
    {
        self::assertSame([], $this->import($this->imported(issuer: $this->issuer(vatId: null))));
    }

    public function testAnImportedDocumentOfANonPayerIgnoresTheVatIdOfTheIssuer(): void
    {
        self::assertSame([], $this->import($this->imported(issuer: $this->issuer(vatId: 'CZ12'), regime: VatRegime::NonPayer)));
    }

    public function testADocumentInTheTrashIsRefused(): void
    {
        $existing = $this->invoice();
        $existing->trash(new \DateTimeImmutable('2026-11-02'), null, 'Mistake');

        self::assertSame(['document invoice.problem.trashed'], $this->keys($this->check($this->input(), $existing)));
    }

    public function testATaxDocumentOnPaymentIsNeverEdited(): void
    {
        $existing = $this->invoice($this->input(), $this->taxDocuments, 'D820260001');

        self::assertSame(['document invoice.problem.tax_document_fixed'], $this->keys($this->check($this->input(['seriesId' => $this->taxDocuments->id(), 'bankAccount' => null]), $existing)));
    }

    public function testATaxDocumentWithoutATaxPointIsRefused(): void
    {
        self::assertSame(['taxPointDate invoice.problem.tax_point_missing'], $this->keys($this->check($this->input(['taxPointDate' => null]))));
    }

    public function testAnAdvanceNeedsNoTaxPoint(): void
    {
        self::assertSame([], $this->check($this->input(['seriesId' => $this->advances->id(), 'kind' => DocumentKind::Advance, 'taxPointDate' => null])));
    }

    public function testAnEditTakesTheKindOfTheDocumentForTheTaxPoint(): void
    {
        $advance = $this->invoice($this->input(), $this->advances, 'Z920260001');
        $invoice = $this->invoice();

        self::assertSame([], $this->check($this->input(['seriesId' => $this->advances->id(), 'taxPointDate' => null]), $advance));
        self::assertSame(['taxPointDate invoice.problem.tax_point_missing'], $this->keys($this->check($this->input(['taxPointDate' => null, 'kind' => DocumentKind::Advance]), $invoice)));
    }

    public function testEveryProblemOfTheDocumentIsReportedAtOnce(): void
    {
        self::assertSame([
            'taxPointDate invoice.problem.tax_point_missing',
            'dueDate invoice.problem.due_date_missing',
            'variableSymbol invoice.problem.variable_symbol_invalid',
            'orderNumber invoice.problem.too_long',
            'constantSymbol invoice.problem.constant_symbol_invalid',
            'bankAccount invoice.problem.bank_account_foreign',
        ], $this->keys($this->check($this->input([
            'taxPointDate' => null,
            'dueDate' => null,
            'variableSymbol' => 'A',
            'orderNumber' => str_repeat('x', Invoice::MAX_ORDER_NUMBER_LENGTH + 1),
            'constantSymbol' => 'A',
            'bankAccount' => null,
        ]))));
    }

    public function testADocumentWithoutADueDateIsRefused(): void
    {
        self::assertSame(['dueDate invoice.problem.due_date_missing'], $this->keys($this->check($this->input(['dueDate' => null]))));
    }

    #[DataProvider('validVariableSymbols')]
    public function testAVariableSymbolOfOneToTenDigitsIsAccepted(string $symbol): void
    {
        self::assertSame([], $this->check($this->input(['variableSymbol' => $symbol])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validVariableSymbols(): iterable
    {
        yield 'none' => [''];
        yield 'one digit' => ['7'];
        yield 'ten digits' => ['1234567890'];
        yield 'zeros' => ['0000000000'];
    }

    #[DataProvider('invalidVariableSymbols')]
    public function testAVariableSymbolOtherThanOneToTenDigitsIsRefused(string $symbol): void
    {
        self::assertSame(['variableSymbol invoice.problem.variable_symbol_invalid'], $this->keys($this->check($this->input(['variableSymbol' => $symbol]))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVariableSymbols(): iterable
    {
        yield 'eleven digits' => ['12345678901'];
        yield 'a letter first' => ['A1'];
        yield 'a letter last' => ['1A'];
        yield 'a space first' => [' 1'];
        yield 'a space last' => ['1 '];
        yield 'a trailing newline' => ["1\n"];
        yield 'a sign' => ['-1'];
    }

    public function testAnOrderNumberOfTheLongestLengthIsAccepted(): void
    {
        self::assertSame([], $this->check($this->input(['orderNumber' => str_repeat('č', Invoice::MAX_ORDER_NUMBER_LENGTH)])));
    }

    public function testAnOrderNumberOverTheLongestLengthIsRefused(): void
    {
        self::assertSame(['orderNumber invoice.problem.too_long'], $this->keys($this->check($this->input(['orderNumber' => str_repeat('č', Invoice::MAX_ORDER_NUMBER_LENGTH + 1)]))));
    }

    public function testAConstantSymbolOfTheLongestLengthIsAccepted(): void
    {
        self::assertSame([], $this->check($this->input(['constantSymbol' => '0123456789'])));
        self::assertSame([], $this->check($this->input(['constantSymbol' => ''])));
    }

    public function testAConstantSymbolOverTheLongestLengthIsRefusedOnlyAsTooLong(): void
    {
        self::assertSame(['constantSymbol invoice.problem.too_long'], $this->keys($this->check($this->input(['constantSymbol' => '01234567890']))));
        self::assertSame(['constantSymbol invoice.problem.too_long'], $this->keys($this->check($this->input(['constantSymbol' => 'čččččččččččč']))));
    }

    public function testAConstantSymbolOtherThanDigitsIsRefused(): void
    {
        self::assertSame(['constantSymbol invoice.problem.constant_symbol_invalid'], $this->keys($this->check($this->input(['constantSymbol' => '03A8']))));
        self::assertSame(['constantSymbol invoice.problem.constant_symbol_invalid'], $this->keys($this->check($this->input(['constantSymbol' => 'čččččččččč']))));
    }

    public function testATransferToAnAccountOfTheFirmIsAcceptedInAnyNotation(): void
    {
        self::assertSame([], $this->check($this->input(['bankAccount' => 'cz65 0800 0000 1920 0014 5399'])));
    }

    public function testATransferToAnAccountTheFirmDoesNotHaveIsRefused(): void
    {
        self::assertSame(['bankAccount invoice.problem.bank_account_foreign'], $this->keys($this->check($this->input(['bankAccount' => self::OTHER_IBAN]))));
    }

    public function testATransferWithoutAnAccountIsRefused(): void
    {
        self::assertSame(['bankAccount invoice.problem.bank_account_foreign'], $this->keys($this->check($this->input(['bankAccount' => null]))));
    }

    public function testATransferToSomethingOtherThanAnIbanIsRefused(): void
    {
        self::assertSame(['bankAccount invoice.problem.bank_account_foreign'], $this->keys($this->check($this->input(['bankAccount' => 'not an account']))));
    }

    public function testAPaymentOtherThanATransferNeedsNoAccount(): void
    {
        self::assertSame([], $this->check($this->input(['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null])));
        self::assertSame([], $this->check($this->input(['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => 'not an account'])));
    }

    public function testAnEditKeepsTheAccountTheFirmHasSinceArchived(): void
    {
        $existing = $this->invoice($this->input(['bankAccount' => self::OTHER_IBAN]));

        self::assertSame([], $this->check($this->input(['bankAccount' => self::OTHER_IBAN]), $existing));
        self::assertSame(['bankAccount invoice.problem.bank_account_foreign'], $this->keys($this->check($this->input(['bankAccount' => 'GB82WEST12345698765432']), $existing)));
    }

    public function testAnImportKeepsAnyAccountItWasIssuedWith(): void
    {
        self::assertSame([], $this->import($this->imported($this->importedInput(['bankAccount' => self::OTHER_IBAN]))));
        self::assertSame(['bankAccount invoice.problem.bank_account_foreign'], $this->keys($this->import($this->imported($this->importedInput(['bankAccount' => 'not an account'])))));
    }

    public function testADocumentThatAsksForNothingNeedsNoAccount(): void
    {
        self::assertSame([], $this->check($this->taxDocument(['bankAccount' => null])));
    }

    public function testADocumentThatAsksForNothingStillNamesOnlyAnAccountOfTheFirm(): void
    {
        self::assertSame(['bankAccount invoice.problem.bank_account_foreign'], $this->keys($this->check($this->taxDocument(['bankAccount' => self::OTHER_IBAN]))));
        self::assertSame([], $this->check($this->taxDocument(['bankAccount' => self::IBAN])));
    }

    public function testAnImportWithoutASeriesIsRefused(): void
    {
        self::assertSame(['number invoice.problem.import_number_missing'], $this->keys($this->import($this->imported($this->importedInput(['seriesId' => null])))));
    }

    public function testAnImportWithoutANumberIsRefused(): void
    {
        self::assertSame(['number invoice.problem.import_number_missing'], $this->keys($this->import($this->imported($this->importedInput(['typedNumber' => null])))));
    }

    public function testAnEditThatKeepsTheNumberIsNotHeldToIt(): void
    {
        $existing = $this->invoice();
        $existing->markDelivered(new \DateTimeImmutable('2026-11-02'));
        $this->goneOut = true;
        $this->taken = [$existing->numberKey()];

        self::assertSame([], $this->check($this->input(['typedNumber' => ' 20260001 ']), $existing));
    }

    public function testADeliveredDocumentKeepsItsNumber(): void
    {
        $existing = $this->invoice();
        $existing->markDelivered(new \DateTimeImmutable('2026-11-02'));

        self::assertSame(['number invoice.problem.number_locked'], $this->keys($this->check($this->input(['typedNumber' => '20260002']), $existing)));
    }

    public function testADocumentSentOutKeepsItsNumber(): void
    {
        $existing = $this->invoice();
        $this->goneOut = true;
        $this->queued = true;

        self::assertSame(['number invoice.problem.number_locked_sent'], $this->keys($this->check($this->input(['typedNumber' => '20260002']), $existing)));
    }

    public function testADocumentQueuedToBeSentKeepsItsNumber(): void
    {
        $existing = $this->invoice();
        $this->queued = true;

        self::assertSame(['number invoice.problem.number_locked_sending'], $this->keys($this->check($this->input(['typedNumber' => '20260002']), $existing)));
    }

    public function testANewDocumentIsNotHeldToSends(): void
    {
        $this->goneOut = true;
        $this->queued = true;

        self::assertSame([], $this->check($this->input(['typedNumber' => '20260002'])));
    }

    public function testAnUnknownSeriesIsRefused(): void
    {
        self::assertSame(['series invoice.problem.series_unknown'], $this->keys($this->check($this->input(['seriesId' => 'unknown']))));
    }

    public function testADocumentWithoutASeriesTakesTheDefault(): void
    {
        self::assertSame([], $this->check($this->input(['seriesId' => null])));
        self::assertSame(['number invoice.problem.number_out_of_range'], $this->keys($this->check($this->input(['seriesId' => null, 'typedNumber' => '202699999999999']))));
    }

    public function testTheStandardSeriesIsRefusedWhileAnArchivedSeriesHasItsName(): void
    {
        $archived = new Series('standard', SeriesFormat::fromString('X{NNNN}'), DocumentKind::Invoice);
        $archived->archive();
        $this->every = [$archived];
        $this->defaults[DocumentKind::Invoice->value] = Series::standard(DocumentKind::Invoice);

        self::assertSame(['series invoice.problem.series_archived'], $this->keys($this->check($this->input(['seriesId' => null]))));
    }

    public function testTheStandardSeriesIsGivenWhileNoOtherSeriesHasItsName(): void
    {
        $this->every = [new Series('Other', SeriesFormat::fromString('X{NNNN}'), DocumentKind::Invoice)];
        $this->defaults[DocumentKind::Invoice->value] = Series::standard(DocumentKind::Invoice);

        self::assertSame([], $this->check($this->input(['seriesId' => null])));
    }

    public function testAStoredDefaultSeriesIsNeverTaken(): void
    {
        $default = Series::standard(DocumentKind::Invoice);
        $this->every = [$default, new Series('standard', SeriesFormat::fromString('X{NNNN}'), DocumentKind::Invoice)];
        $this->defaults[DocumentKind::Invoice->value] = $default;

        self::assertSame([], $this->check($this->input(['seriesId' => null])));
    }

    public function testAnEditWithoutASeriesKeepsTheSeriesOfTheDocument(): void
    {
        $existing = $this->invoice();
        $taken = new Series('Standard', SeriesFormat::fromString('X{NNNN}'), DocumentKind::Invoice);
        $taken->archive();
        $this->every = [$taken];
        $this->defaults[DocumentKind::Invoice->value] = new Series('standard', SeriesFormat::fromString('F{NNNN}'), DocumentKind::Invoice);

        self::assertSame(['number invoice.problem.number_out_of_range'], $this->keys($this->check($this->input(['seriesId' => null, 'typedNumber' => '202699999999999']), $existing)));
    }

    public function testASeriesOfAnotherKindIsRefused(): void
    {
        self::assertSame(['series invoice.problem.series_kind'], $this->keys($this->check($this->input(['seriesId' => $this->advances->id()]))));
    }

    public function testAnEditTakesASeriesOfTheKindOfTheDocument(): void
    {
        $existing = $this->invoice($this->input(), $this->advances, 'Z920260001');
        $other = new Series('Other advances', SeriesFormat::fromString('Z8{YYYY}{NNNN}'), DocumentKind::Advance);
        $this->every[] = $other;

        self::assertSame([], $this->check($this->input(['seriesId' => $other->id()]), $existing));
        self::assertSame(['series invoice.problem.series_kind'], $this->keys($this->check($this->input(['seriesId' => $this->series->id(), 'kind' => DocumentKind::Advance]), $existing)));
    }

    public function testAnArchivedSeriesIsRefusedForANewDocument(): void
    {
        $this->series->archive();

        self::assertSame(['series invoice.problem.series_archived'], $this->keys($this->check($this->input())));
    }

    public function testAnEditMayStayInItsArchivedSeries(): void
    {
        $existing = $this->invoice();
        $this->series->archive();

        self::assertSame([], $this->check($this->input(['typedNumber' => '20260002']), $existing));
    }

    public function testAnEditMayNotMoveToAnArchivedSeries(): void
    {
        $existing = $this->invoice();
        $archived = new Series('Old', SeriesFormat::fromString('S{YYYY}{NNNN}'), DocumentKind::Invoice);
        $archived->archive();
        $this->every[] = $archived;

        self::assertSame(['series invoice.problem.series_archived'], $this->keys($this->check($this->input(['seriesId' => $archived->id()]), $existing)));
    }

    public function testAnImportMayNameAnArchivedSeries(): void
    {
        $this->series->archive();

        self::assertSame([], $this->import($this->imported()));
    }

    #[DataProvider('invalidNumbers')]
    public function testATypedNumberWithoutAKeyOrOverTheLongestLengthIsRefused(string $number): void
    {
        self::assertSame(['number invoice.problem.number_invalid'], $this->keys($this->check($this->input(['typedNumber' => $number]))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'blank' => ['   '];
        yield 'punctuation only' => ['-/-'];
        yield 'one character too long' => [str_repeat('A', Invoice::MAX_NUMBER_LENGTH + 1)];
        yield 'a key too long' => [str_repeat('ß', Invoice::MAX_NUMBER_LENGTH / 2 + 1)];
    }

    #[DataProvider('longestNumbers')]
    public function testATypedNumberOfTheLongestLengthIsAccepted(string $number): void
    {
        self::assertSame([], $this->check($this->input(['typedNumber' => $number])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longestNumbers(): iterable
    {
        yield 'characters' => [str_repeat('A', Invoice::MAX_NUMBER_LENGTH)];
        yield 'characters between spaces' => [' '.str_repeat('A', Invoice::MAX_NUMBER_LENGTH).' '];
        yield 'characters of two bytes' => [str_repeat('Č', Invoice::MAX_NUMBER_LENGTH)];
        yield 'a key of the longest length' => [str_repeat('ß', Invoice::MAX_NUMBER_LENGTH / 2)];
    }

    public function testANumberTheCountryCannotUseIsRefused(): void
    {
        self::assertSame(['number invoice.problem.number_not_accepted'], $this->keys($this->check($this->input(['typedNumber' => '2026*0099']))));
    }

    public function testTheCountryIsAskedAboutTheTrimmedNumber(): void
    {
        self::assertSame([], $this->check($this->input(['typedNumber' => ' 20260099 '])));
    }

    public function testANumberBeyondTheCounterOfTheSeriesIsRefused(): void
    {
        self::assertSame(['number invoice.problem.number_out_of_range'], $this->keys($this->check($this->input(['typedNumber' => '202699999999999']))));
    }

    public function testANumberIsReadOnTheDayOfIssue(): void
    {
        self::assertSame([], $this->check($this->input(['typedNumber' => '202599999999999'])));
    }

    public function testANumberAnotherDocumentHasIsRefused(): void
    {
        $this->taken = ['20260099'];

        self::assertSame(['number invoice.problem.number_taken'], $this->keys($this->check($this->input(['typedNumber' => '2026-0099']))));
    }

    public function testAnEditMayRetypeItsOwnNumber(): void
    {
        $existing = $this->invoice(number: 'A-1');
        $this->taken = ['A1'];

        self::assertSame([], $this->check($this->input(['typedNumber' => 'a1']), $existing));
    }

    public function testAnUnknownCustomerIsRefused(): void
    {
        self::assertSame(['customer invoice.problem.customer_unknown'], $this->keys($this->check($this->input(['customerId' => 'unknown']))));
    }

    public function testAnArchivedCustomerIsRefusedForANewDocument(): void
    {
        $this->customer->archive(new \DateTimeImmutable('2026-10-01'));

        self::assertSame(['customer invoice.problem.customer_archived'], $this->keys($this->check($this->input())));
    }

    public function testAnEditKeepsItsArchivedCustomer(): void
    {
        $existing = $this->invoice();
        $this->customer->archive(new \DateTimeImmutable('2026-10-01'));

        self::assertSame([], $this->check($this->input(), $existing));
    }

    public function testAnEditMayNotMoveToAnArchivedCustomer(): void
    {
        $existing = $this->invoice();
        $other = $this->party('Other Customer a.s.');
        $other->archive(new \DateTimeImmutable('2026-10-01'));

        self::assertSame(['customer invoice.problem.customer_archived'], $this->keys($this->check($this->input(['customerId' => $other->id()]), $existing)));
    }

    public function testAnImportMayNameAnArchivedCustomer(): void
    {
        $this->customer->archive(new \DateTimeImmutable('2026-10-01'));

        self::assertSame([], $this->import($this->imported()));
    }

    public function testAnArchivedCustomerWithoutABillingAddressIsRefusedForBoth(): void
    {
        $this->customer->archive(new \DateTimeImmutable('2026-10-01'));
        unset($this->billing[$this->customer->id()]);

        self::assertSame(['customer invoice.problem.customer_archived', 'customer invoice.problem.customer_address_incomplete'], $this->keys($this->check($this->input())));
    }

    public function testACustomerWithoutABillingAddressIsRefused(): void
    {
        unset($this->billing[$this->customer->id()]);

        self::assertSame(['customer invoice.problem.customer_address_incomplete'], $this->keys($this->check($this->input())));
    }

    #[DataProvider('incompleteAddresses')]
    public function testACustomerCopyMissingAPartOfItsAddressIsRefused(string $part): void
    {
        self::assertSame(['customer invoice.problem.customer_address_incomplete'], $this->keys($this->check($this->input(['customerOnDocument' => $this->copy([$part => ' '])]))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteAddresses(): iterable
    {
        yield 'name' => ['name'];
        yield 'street' => ['street'];
        yield 'city' => ['city'];
        yield 'postal code' => ['postalCode'];
        yield 'country' => ['countryCode'];
    }

    public function testAnImportedCustomerCopyNeedsOnlyItsNameAndCountry(): void
    {
        $copy = $this->copy(['street' => '', 'city' => ' ', 'postalCode' => '']);

        self::assertSame([], $this->import($this->imported($this->importedInput(['customerOnDocument' => $copy]))));
    }

    public function testAnImportedCustomerCopyWithoutANameOrCountryIsRefused(): void
    {
        self::assertSame(['customer invoice.problem.customer_address_incomplete'], $this->keys($this->import($this->imported($this->importedInput(['customerOnDocument' => $this->copy(['name' => ' '])])))));
        self::assertSame(['customer invoice.problem.customer_address_incomplete'], $this->keys($this->import($this->imported($this->importedInput(['customerOnDocument' => $this->copy(['countryCode' => ' '])])))));
    }

    public function testACustomerCopyOfTheLongestLengthsIsAccepted(): void
    {
        self::assertSame([], $this->check($this->input(['customerOnDocument' => $this->copy(self::longest())])));
    }

    #[DataProvider('tooLongParts')]
    public function testACustomerCopyWithAPartOverItsLongestLengthIsRefused(string $part): void
    {
        $longest = self::longest();
        $value = 'countryCode' === $part ? 'CZE' : $longest[$part].'č';

        self::assertSame(['customer invoice.problem.too_long'], $this->keys($this->check($this->input(['customerOnDocument' => $this->copy([$part => $value])]))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tooLongParts(): iterable
    {
        foreach (array_keys(self::longest()) as $part) {
            yield $part => [$part];
        }
    }

    public function testAGivenCustomerCopyWithAnInvalidVatIdIsRefused(): void
    {
        self::assertSame(['customer invoice.problem.customer_vat_id_invalid'], $this->keys($this->check($this->input(['customerOnDocument' => $this->copy(['vatId' => 'CZ12'])]))));
        self::assertSame([], $this->check($this->input(['customerOnDocument' => $this->copy(['vatId' => null])])));
    }

    public function testAStoredCustomerCopyIsNotCheckedForItsVatId(): void
    {
        $existing = $this->invoice($this->input(), customer: $this->copy(['vatId' => 'CZ12']));

        self::assertSame([], $this->check($this->input(), $existing));
    }

    #[DataProvider('typedTexts')]
    public function testAnUnprintableTypedTextIsRefusedByItsField(string $field, string $input): void
    {
        $overrides = str_starts_with($input, 'line.') ? ['lines' => ['line.text' === $input ? $this->line(text: 'Work 🙂') : $this->line(unit: 'h 🙂')]] : [$input => '2026🙂0099'];
        if ('orderNumber' === $input || 'description' === $input || 'textBefore' === $input || 'textAfter' === $input) {
            $overrides = [$input => 'Text 🙂'];
        }

        self::assertEquals([new IssueProblem($field, 'invoice.problem.unprintable', ['characters' => '🙂'])], $this->check($this->input($overrides)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typedTexts(): iterable
    {
        yield 'number' => ['number', 'typedNumber'];
        yield 'order number' => ['orderNumber', 'orderNumber'];
        yield 'description' => ['description', 'description'];
        yield 'text before' => ['textBefore', 'textBefore'];
        yield 'text after' => ['textAfter', 'textAfter'];
        yield 'line text' => ['lines.0.text', 'line.text'];
        yield 'line unit' => ['lines.0.unit', 'line.unit'];
    }

    public function testEveryUnprintableTextIsReportedAtOnce(): void
    {
        self::assertEquals([
            new IssueProblem('description', 'invoice.problem.unprintable', ['characters' => '🙂']),
            new IssueProblem('textAfter', 'invoice.problem.unprintable', ['characters' => '🙃']),
            new IssueProblem('customer', 'invoice.problem.unprintable_part', ['part' => 'name', 'characters' => '🙂']),
            new IssueProblem('customer', 'invoice.problem.unprintable_part', ['part' => 'web', 'characters' => '🙃']),
        ], $this->check($this->input(['description' => 'Text 🙂', 'textAfter' => 'Text 🙃', 'customerOnDocument' => $this->copy(['name' => 'Name 🙂', 'web' => 'Web 🙃'])])));
    }

    #[DataProvider('printedParts')]
    public function testAnUnprintablePartOfTheCustomerIsRefused(string $part, string $printed): void
    {
        $problems = $this->check($this->input(['customerOnDocument' => $this->copy([$part => 'vatId' === $part ? 'CZ🙂🙃' : 'X 🙂🙃'])]));
        $unprintable = new IssueProblem('customer', 'invoice.problem.unprintable_part', ['part' => $printed, 'characters' => '🙂, 🙃']);

        self::assertEquals('vatId' === $part ? [new IssueProblem('customer', 'invoice.problem.customer_vat_id_invalid'), $unprintable] : [$unprintable], $problems);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function printedParts(): iterable
    {
        yield 'name' => ['name', 'name'];
        yield 'street' => ['street', 'street'];
        yield 'city' => ['city', 'city'];
        yield 'postal code' => ['postalCode', 'postal_code'];
        yield 'registration number' => ['registrationNumber', 'registration_number'];
        yield 'VAT id' => ['vatId', 'vat_id'];
        yield 'register entry' => ['registerEntry', 'register_entry'];
        yield 'email' => ['email', 'email'];
        yield 'phone' => ['phone', 'phone'];
        yield 'web' => ['web', 'web'];
    }

    public function testACustomerCopyWithoutOptionalPartsPrints(): void
    {
        self::assertSame([], $this->check($this->input(['customerOnDocument' => $this->copy(['registrationNumber' => null, 'vatId' => null, 'email' => null])])));
    }

    public function testAnUnprintablePartOfTheFirmIsRefusedOnANewDocument(): void
    {
        $this->firm = $this->firmOf(register: 'Register 🙂');

        self::assertEquals([new IssueProblem('firm', 'invoice.problem.unprintable_part', ['part' => 'register_entry', 'characters' => '🙂'])], $this->check($this->input()));
    }

    public function testTheContactsOfTheFirmCountOnANewDocument(): void
    {
        $this->settings->change(TotalRounding::WholeUnit, '', '', '', 'billing🙂@example.com', '', '');

        self::assertEquals([new IssueProblem('firm', 'invoice.problem.unprintable_part', ['part' => 'email', 'characters' => '🙂'])], $this->check($this->input()));
    }

    public function testAnEditIsNotHeldToHowTheFirmPrintsToday(): void
    {
        $existing = $this->invoice();
        $this->firm = $this->firmOf(register: 'Register 🙂');

        self::assertSame([], $this->check($this->input(), $existing));
    }

    public function testAnImportedIssuerIsCheckedForPrintingInsteadOfTheFirm(): void
    {
        $issuer = $this->issuer(name: 'Example 🙂');
        $this->firm = $this->firmOf(register: 'Register 🙃');

        self::assertEquals([new IssueProblem('firm', 'invoice.problem.unprintable_part', ['part' => 'name', 'characters' => '🙂'])], $this->import($this->imported(issuer: $issuer)));
    }

    #[DataProvider('incompleteAddresses')]
    public function testAnImportedIssuerMissingAPartOfItsAddressIsRefused(string $part): void
    {
        $issuer = 'name' === $part ? $this->issuer(name: ' ') : $this->issuer(address: $this->address([$part => ' ']));
        // An issuer of no country has no rules to be checked by at all.
        $expected = 'countryCode' === $part ? 'firm invoice.problem.firm_country_unsupported' : 'firm invoice.problem.import_issuer_incomplete';

        self::assertSame([$expected], $this->keys($this->import($this->imported(issuer: $issuer))));
    }

    public function testAnImportedIssuerOverALongestLengthIsRefused(): void
    {
        self::assertSame(['firm invoice.problem.too_long'], $this->keys($this->import($this->imported(issuer: $this->issuer(name: str_repeat('č', DocumentParty::MAX_NAME + 1))))));
    }

    public function testAFirmInACountryWithoutRulesMayNotIssue(): void
    {
        $this->firm = $this->firmOf(country: 'DE');

        self::assertSame(['firm invoice.problem.firm_country_unsupported'], $this->keys($this->check($this->input(['typedNumber' => '20260099', 'description' => 'Text 🙂']))));
    }

    public function testTheProblemsFoundBeforeAnUnsupportedCountryAreKept(): void
    {
        $this->firm = $this->firmOf(country: 'DE');

        self::assertSame([
            'dueDate invoice.problem.due_date_missing',
            'firm invoice.problem.firm_country_unsupported',
        ], $this->keys($this->check($this->input(['dueDate' => null]))));
    }

    public function testAnEditAnswersToTheCountryItWasIssuedIn(): void
    {
        $existing = $this->invoice();
        $this->firm = $this->firmOf(country: 'DE');

        self::assertSame([], $this->check($this->input(['typedNumber' => '20260099']), $existing));
    }

    public function testAnImportAnswersToTheCountryOfItsIssuer(): void
    {
        $issuer = $this->issuer();
        $this->firm = $this->firmOf(country: 'DE');

        self::assertSame([], $this->import($this->imported(issuer: $issuer)));
        self::assertSame(['firm invoice.problem.firm_country_unsupported'], $this->keys($this->import($this->imported(issuer: $this->issuer(address: $this->address(['countryCode' => 'DE']))))));
    }

    public function testDomesticReverseChargeIsJudgedByTheCountryOfTheFirm(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge, supplyCode: '1')];
        self::assertSame([], $this->check($this->input(['lines' => $lines])));

        $this->firm = $this->firmOf(country: 'SK');

        self::assertSame(['lines.0.supplyCode invoice.problem.supply_code_not_allowed'], $this->keys($this->check($this->input(['lines' => $lines]))));
    }

    public function testAnEditJudgesReverseChargeByTheCountryItWasIssuedIn(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge, supplyCode: '1')];
        $existing = $this->invoice();
        $this->firm = $this->firmOf(country: 'SK');

        self::assertSame([], $this->check($this->input(['lines' => $lines]), $existing));
    }

    public function testAnImportJudgesReverseChargeByTheCountryOfItsIssuer(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge, supplyCode: '1')];
        $issuer = $this->issuer();
        $this->firm = $this->firmOf(country: 'SK');

        self::assertSame([], $this->import($this->imported($this->importedInput(['lines' => $lines]), $issuer)));
    }

    public function testReverseChargeNeedsTheVatIdOfTheCustomerCopy(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge)];

        self::assertSame(['customer invoice.problem.reverse_charge_customer_vat_id'], $this->keys($this->check($this->input(['lines' => $lines, 'customerOnDocument' => $this->copy(['vatId' => null])]))));
    }

    public function testANewDocumentTakesTheRegimeOfTheFirm(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge)];
        $this->firm = $this->firmOf(status: VatStatus::NotRegistered, vatId: null);

        self::assertSame(['lines.0.treatment invoice.problem.reverse_charge_payer'], $this->keys($this->check($this->input(['lines' => $lines]))));
    }

    public function testAnEditKeepsItsRegime(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge)];
        $payer = $this->invoice();
        $nonPayer = $this->invoice(regime: VatRegime::NonPayer);
        $this->firm = $this->firmOf(status: VatStatus::NotRegistered, vatId: null);

        self::assertSame([], $this->check($this->input(['lines' => $lines]), $payer));
        $this->firm = $this->firmOf();
        self::assertSame(['lines.0.treatment invoice.problem.reverse_charge_payer'], $this->keys($this->check($this->input(['lines' => $lines]), $nonPayer)));
    }

    public function testAnImportKeepsItsRegime(): void
    {
        $lines = [$this->line(treatment: TaxTreatment::ReverseCharge)];

        self::assertSame(['lines.0.treatment invoice.problem.reverse_charge_payer'], $this->keys($this->import($this->imported($this->importedInput(['lines' => $lines]), regime: VatRegime::NonPayer))));
    }

    public function testANewDocumentRoundsAsTheFirmDoes(): void
    {
        $lines = [$this->line('-0.40')];
        self::assertSame([], $this->check($this->input(['lines' => $lines])));

        $this->settings->change(TotalRounding::None, '', '', '', '', '', '');

        self::assertSame(['lines invoice.problem.payable_negative'], $this->keys($this->check($this->input(['lines' => $lines]))));
    }

    public function testAnEditKeepsItsRounding(): void
    {
        $lines = [$this->line('-0.40')];
        $existing = $this->invoice(rounding: TotalRounding::None);

        self::assertSame(['lines invoice.problem.payable_negative'], $this->keys($this->check($this->input(['lines' => $lines]), $existing)));
    }

    public function testAnImportKeepsItsRounding(): void
    {
        $lines = [$this->line('-0.40')];

        self::assertSame(['lines invoice.problem.payable_negative'], $this->keys($this->import($this->imported($this->importedInput(['lines' => $lines]), rounding: TotalRounding::None))));
        self::assertSame([], $this->import($this->imported($this->importedInput(['lines' => $lines]))));
    }

    public function testTheAmountsOfAnAdvanceAreCheckedOnItsDayOfIssue(): void
    {
        $this->settings->change(TotalRounding::None, '', '', '', '', '', '');

        self::assertSame(['lines invoice.problem.payable_negative'], $this->keys($this->check($this->input(['seriesId' => $this->advances->id(), 'kind' => DocumentKind::Advance, 'taxPointDate' => null, 'lines' => [$this->line('-0.40')]]))));
    }

    public function testAnEditChecksTheAmountsOnTheDayOfItsOwnKind(): void
    {
        $existing = $this->invoice($this->input(), $this->advances, 'Z920260001', rounding: TotalRounding::None);

        self::assertSame(['lines invoice.problem.payable_negative'], $this->keys($this->check($this->input(['seriesId' => $this->advances->id(), 'taxPointDate' => null, 'lines' => [$this->line('-0.40')]]), $existing)));
    }

    public function testTheAmountsAreNotCheckedWhileAnotherRuleFails(): void
    {
        self::assertSame(['dueDate invoice.problem.due_date_missing'], $this->keys($this->check($this->input(['dueDate' => null, 'lines' => [$this->line('-100.00')]]))));
    }

    public function testTheAmountsAreNotCheckedWithoutADayForTheRates(): void
    {
        self::assertSame(['taxPointDate invoice.problem.tax_point_missing'], $this->keys($this->check($this->input(['taxPointDate' => null, 'lines' => [$this->line('-100.00')]]))));
    }

    public function testTheLinesAreCheckedOnTheTaxPoint(): void
    {
        $lines = [$this->line(kind: VatRateKind::SecondReduced)];

        self::assertSame([], $this->check($this->input(['lines' => $lines, 'issueDate' => new \DateTimeImmutable('2026-11-20'), 'taxPointDate' => new \DateTimeImmutable('2026-11-01')])));
        self::assertSame(['lines.0.rate invoice.problem.line_rate_missing'], $this->keys($this->check($this->input(['lines' => $lines, 'taxPointDate' => new \DateTimeImmutable('2026-11-20')]))));
    }

    public function testACorrectionTakesTheRatesOfTheInvoiceItCorrects(): void
    {
        $original = $this->invoice($this->input(['lines' => [$this->line(kind: VatRateKind::SecondReduced)]]));
        $priced = new LinePricing(new InvoiceCalculator())->priced([$this->line(kind: VatRateKind::SecondReduced)], VatRegime::Payer, new ExampleCountry(), new \DateTimeImmutable('2026-11-01'))[0];
        $this->lines[$original->id()] = [new InvoiceLine($original, 0, 'Work', 'h', VatRateKind::SecondReduced, null, $priced->values, $priced->amounts)];

        self::assertSame([], $this->check($this->input([
            'seriesId' => $this->corrections->id(),
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-20'),
            'bankAccount' => null,
            'variableSymbol' => '',
            'lines' => [$this->line('-100.00', kind: VatRateKind::SecondReduced)],
            'correction' => new CorrectionInput($original->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')),
        ])));
    }

    public function testACorrectionLinkOnAnotherKindIsRefused(): void
    {
        self::assertSame(['document invoice.problem.correction_kind'], $this->keys($this->check($this->input(['correction' => new CorrectionInput('invoice-1', 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20'))]))));
    }

    public function testAnInvoiceMayDeductWhatItsCustomerMayDeduct(): void
    {
        [$first, $second] = $this->taxDocumentsOnOffer('');

        self::assertSame([], $this->check($this->input(['lines' => [$this->line(), $this->deduction($first)]])));
        self::assertSame([], $this->check($this->input(['lines' => [$this->line(), $this->deduction($first), $this->deduction($second)]])));
    }

    public function testAnInvoiceMayNotDeductWhatIsNotOnOffer(): void
    {
        [$first] = $this->taxDocumentsOnOffer('');

        self::assertSame(['lines invoice.problem.deduction_invalid'], $this->keys($this->check($this->input(['lines' => [$this->line(), $this->deduction($first), $this->deduction('unknown')]]))));
    }

    public function testAnEditMayDeductWhatItDeductsAlready(): void
    {
        $existing = $this->invoice();
        [$first] = $this->taxDocumentsOnOffer($existing->id());

        self::assertSame([], $this->check($this->input(['lines' => [$this->line(), $this->deduction($first)]]), $existing));
        self::assertSame(['lines invoice.problem.deduction_invalid'], $this->keys($this->check($this->input(['lines' => [$this->line(), $this->deduction($first)]]))));
    }

    public function testOnlyAnInvoiceDeducts(): void
    {
        [$first] = $this->taxDocumentsOnOffer('');
        $advance = ['seriesId' => $this->advances->id(), 'kind' => DocumentKind::Advance, 'lines' => [$this->line(), $this->deduction($first)]];

        self::assertSame(['lines invoice.problem.deduction_invalid'], $this->keys($this->check($this->input($advance))));
    }

    public function testAnEditDeductsByTheKindOfTheDocument(): void
    {
        $existing = $this->invoice($this->input(), $this->advances, 'Z920260001');
        [$first] = $this->taxDocumentsOnOffer($existing->id());

        self::assertSame(['lines invoice.problem.deduction_invalid'], $this->keys($this->check($this->input(['seriesId' => $this->advances->id(), 'lines' => [$this->line(), $this->deduction($first)]]), $existing)));
    }

    public function testEveryRuleReportsInItsOrder(): void
    {
        $this->firm = $this->firmOf(register: '');

        self::assertSame([
            'firm invoice.problem.firm_register_entry_missing',
            'dueDate invoice.problem.due_date_missing',
            'series invoice.problem.series_unknown',
            'customer invoice.problem.customer_unknown',
            'description invoice.problem.unprintable',
            'lines invoice.problem.deduction_invalid',
            'document invoice.problem.correction_kind',
            'lines.1.vat invoice.problem.vat_invalid',
        ], $this->keys($this->check($this->input([
            'dueDate' => null,
            'seriesId' => 'unknown',
            'customerId' => 'unknown',
            'description' => 'Text 🙂',
            'lines' => [$this->line(), $this->line(treatment: TaxTreatment::NotApplicable, kind: null), $this->deduction('unknown')],
            'correction' => new CorrectionInput('invoice-1', 'Returned goods', CorrectionType::Change, null),
        ]))));
    }

    /**
     * @return array<string, string>
     */
    private static function longest(): array
    {
        return [
            'name' => str_repeat('č', DocumentParty::MAX_NAME),
            'registrationNumber' => str_repeat('č', DocumentParty::MAX_REGISTRATION_NUMBER),
            'vatId' => 'DE123456789012',
            'registerEntry' => str_repeat('č', DocumentParty::MAX_REGISTER_ENTRY),
            'email' => str_repeat('č', DocumentParty::MAX_EMAIL),
            'phone' => str_repeat('č', DocumentParty::MAX_PHONE),
            'web' => str_repeat('č', DocumentParty::MAX_WEB),
            'street' => str_repeat('č', DocumentParty::MAX_STREET),
            'city' => str_repeat('č', DocumentParty::MAX_CITY),
            'postalCode' => str_repeat('č', DocumentParty::MAX_POSTAL_CODE),
            'countryCode' => 'CZ',
            'logo' => str_repeat('č', DocumentParty::MAX_IMAGE_NAME),
            'stamp' => str_repeat('č', DocumentParty::MAX_IMAGE_NAME),
        ];
    }

    /**
     * @return list<string>
     */
    private function taxDocumentsOnOffer(string $edited): array
    {
        $ids = [];
        foreach (['D820260001', 'D820260002'] as $index => $number) {
            $advance = $this->invoice($this->input(), $this->advances, 'Z92026000'.($index + 1));
            $document = $this->invoice($this->input(), $this->taxDocuments, $number);
            $document->taxesPayment($advance->id(), 'payment-'.$index);
            $ids[] = $document->id();
        }
        $this->offered[$edited] = $ids;

        return $ids;
    }

    private function deduction(string $document): LineInput
    {
        return new LineInput('Deduction', Quantity::fromString('1'), '', Money::of('-500.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::NotApplicable, null, null, Percent::fromString('0'), $document);
    }

    /**
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    private function taxDocument(array $overrides): InvoiceInput
    {
        return $this->input([...['seriesId' => $this->taxDocuments->id(), 'kind' => DocumentKind::PaymentTaxDocument], ...$overrides]);
    }

    /**
     * @param list<IssueProblem> $problems
     *
     * @return list<string>
     */
    private function keys(array $problems): array
    {
        return array_map(static fn (IssueProblem $problem): string => $problem->field.' '.$problem->key, $problems);
    }

    /**
     * @return list<IssueProblem>
     */
    private function check(InvoiceInput $input, ?Invoice $existing = null): array
    {
        return $existing instanceof Invoice ? $this->rules()->check($input, $existing) : $this->rules()->check($input);
    }

    /**
     * @return list<IssueProblem>
     */
    private function import(ImportedDocumentInput $import): array
    {
        return $this->rules()->checkImported($import);
    }

    private function rules(): IssueRules
    {
        $pricing = new LinePricing(new InvoiceCalculator());
        $printable = new EmojiPrintableText();
        $firms = self::createStub(LegalEntities::class);
        $firms->method('all')->willReturnCallback(fn (): array => [$this->firm]);
        $instanceFirm = new InstanceFirm($firms);

        $parties = self::createStub(PartyQuery::class);
        $parties->method('find')->willReturnCallback(fn (string $id): ?Party => $this->parties[$id] ?? null);

        $catalog = self::createStub(SeriesCatalog::class);
        $catalog->method('every')->willReturnCallback(fn (): array => $this->every);
        $catalog->method('get')->willReturnCallback(fn (string $id): Series => array_find($this->every, static fn (Series $series): bool => $series->id() === $id) ?? throw new UnknownSeries());
        $catalog->method('defaultFor')->willReturnCallback(fn (DocumentKind $kind): Series => $this->defaults[$kind->value]);

        $store = self::createStub(InvoiceStore::class);
        $store->method('get')->willReturnCallback(fn (string $id): Invoice => $this->invoices[$id] ?? throw new UnknownInvoice());
        $store->method('lines')->willReturnCallback(fn (Invoice $invoice): array => $this->lines[$invoice->id()] ?? []);
        $store->method('correctionsOf')->willReturn([]);
        $store->method('numberTaken')->willReturnCallback(fn (string $key, ?Invoice $except): bool => \in_array($key, $this->taken, true) && $key !== $except?->numberKey());

        $details = self::createStub(PartyDetails::class);
        $details->method('billingAddress')->willReturnCallback(fn (Party $party): ?PartyAddress => $this->billing[$party->id()] ?? null);
        $details->method('invoicingEmails')->willReturn([]);
        $details->method('generalEmail')->willReturn(null);

        $settings = self::createStub(FirmInvoicingSettings::class);
        $settings->method('get')->willReturnCallback(fn (): FirmInvoicing => $this->settings);

        $sends = self::createStub(InvoiceSends::class);
        $sends->method('hasGoneOut')->willReturnCallback(fn (Invoice $invoice): bool => $this->goneOut);
        $sends->method('hasQueued')->willReturnCallback(fn (Invoice $invoice): bool => $this->queued);

        $accounts = self::createStub(FirmBankAccounts::class);
        $accounts->method('ibans')->willReturnCallback(fn (): array => $this->ibans);

        $documents = self::createStub(AdvanceDocuments::class);
        $documents->method('deductibleFor')->willReturnCallback(fn (string $customerId, ?string $except): array => ['taxDocuments' => $this->offered[$except ?? ''] ?? [], 'advances' => []]);
        $texts = self::createStub(PaymentTaxDocumentTexts::class);
        $texts->method('deductionText')->willReturn('Deduction');

        return new IssueRules(
            new InvoicingCountries([new ExampleCountry('CZ'), new ExampleCountry('SK')]),
            $parties,
            $catalog,
            $store,
            new CustomerCopies($details),
            $settings,
            $printable,
            $sends,
            $accounts,
            new LineRules($pricing, $printable, $instanceFirm),
            new DeductibleAdvances($documents, $store, self::createStub(InvoicePayments::class), new AdvanceTaxation($store), $texts),
            new CorrectionRules($store, $pricing),
            $instanceFirm,
        );
    }

    /**
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    private function input(array $overrides = []): InvoiceInput
    {
        return new \ReflectionClass(InvoiceInput::class)->newInstanceArgs([...[
            'seriesId' => $this->series->id(),
            'typedNumber' => null,
            'customerId' => $this->customer->id(),
            'customerOnDocument' => null,
            'issueDate' => new \DateTimeImmutable('2026-11-01'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-01'),
            'dueDate' => new \DateTimeImmutable('2026-11-15'),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'variableSymbol' => '20260001',
            'constantSymbol' => '0308',
            'orderNumber' => 'PO-1',
            'description' => 'Consulting',
            'textBefore' => 'Before',
            'textAfter' => 'After',
            'language' => DocumentLanguage::Czech,
            'lines' => [$this->line()],
        ], ...$overrides]);
    }

    /**
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    private function importedInput(array $overrides = []): InvoiceInput
    {
        return $this->input([...['typedNumber' => '20260099'], ...$overrides]);
    }

    private function imported(?InvoiceInput $document = null, ?DocumentParty $issuer = null, VatRegime $regime = VatRegime::Payer, TotalRounding $rounding = TotalRounding::WholeUnit): ImportedDocumentInput
    {
        return new ImportedDocumentInput($document ?? $this->importedInput(), $issuer ?? $this->issuer(), $regime, $rounding, new \DateTimeImmutable('2026-11-01'), null, null, new ImportedTotals(0, 0, 0, 0), 'example', 'external-1', 'run-1');
    }

    private function invoice(?InvoiceInput $input = null, ?Series $series = null, string $number = '20260001', VatRegime $regime = VatRegime::Payer, TotalRounding $rounding = TotalRounding::WholeUnit, ?DocumentParty $customer = null): Invoice
    {
        $series ??= $this->series;
        $input ??= $this->input();
        $invoice = new Invoice($series, $number, $regime, $rounding, Currency::CZK, $this->issuer(), $input->change($this->customer, $customer ?? $this->copy(), $series->kind()), null, new \DateTimeImmutable('2026-11-01'));
        $this->invoices[$invoice->id()] = $invoice;

        return $invoice;
    }

    private function issuer(?string $name = null, ?string $vatId = 'CZ12345695', ?Address $address = null): DocumentParty
    {
        $firm = DocumentParties::ofIssuer($this->firm, $this->settings);

        return new DocumentParty($name ?? $firm->name, $firm->registrationNumber, $vatId, $address ?? $firm->address, $firm->registerEntry, $firm->email, $firm->phone, $firm->web, $firm->logo, $firm->stamp);
    }

    /**
     * @param array<string, string|null> $overrides parts of the copy or of its address
     */
    private function copy(array $overrides = []): DocumentParty
    {
        $address = array_intersect_key($overrides, array_flip(['street', 'city', 'postalCode', 'countryCode']));
        $parts = array_diff_key($overrides, $address);

        return new \ReflectionClass(DocumentParty::class)->newInstanceArgs([...[
            'name' => 'Example Customer a.s.',
            'registrationNumber' => '87654320',
            'vatId' => 'CZ87654320',
            'address' => $this->address($address),
            'registerEntry' => '',
            'email' => 'billing@example.com',
            'phone' => '+420 000 000 000',
            'web' => 'https://example.com',
            'logo' => null,
            'stamp' => null,
        ], ...$parts]);
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function address(array $overrides = []): Address
    {
        return new \ReflectionClass(Address::class)->newInstanceArgs([...[
            'street' => 'Customer Street 2',
            'city' => 'Customer City',
            'postalCode' => '200 00',
            'countryCode' => 'CZ',
        ], ...$overrides]);
    }

    private function party(string $name): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $this->parties[$party->id()] = $party;
        $this->billing[$party->id()] = new PartyAddress($party, AddressType::Billing, $this->address());

        return $party;
    }

    private function firmOf(VatStatus $status = VatStatus::Registered, ?string $vatId = 'CZ12345695', string $register = 'Example register, entry C 1', string $country = 'CZ'): LegalEntity
    {
        return new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null === $vatId ? null : VatId::fromString($vatId), new Address('Example Street 1', 'Example City', '100 00', $country), $status, $register);
    }

    private function line(string $price = '1000.00', TaxTreatment $treatment = TaxTreatment::Standard, ?VatRateKind $kind = VatRateKind::Base, ?string $supplyCode = null, string $text = 'Work', string $unit = 'h'): LineInput
    {
        return new LineInput($text, Quantity::fromString('1'), $unit, Money::of($price, Currency::CZK), PriceType::WithoutVat, $treatment, $kind, $supplyCode, Percent::fromString('0'));
    }
}
