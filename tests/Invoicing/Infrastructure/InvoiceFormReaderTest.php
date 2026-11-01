<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Infrastructure\Form\InvoiceFormReader;
use App\Invoicing\Infrastructure\Form\InvoiceSubmission;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InvoiceFormReaderTest extends TestCase
{
    private const string FIRM = '019a0000-0000-7000-8000-000000000001';
    private const string SERIES = '019a0000-0000-7000-8000-000000000002';
    private const string PARTY = '019a0000-0000-7000-8000-000000000003';

    public function testReadsAWholeForm(): void
    {
        $submission = $this->read([]);

        self::assertSame([], $submission->errors);
        $input = $submission->input;
        self::assertSame(self::FIRM, $input->firmId);
        self::assertSame(self::SERIES, $input->seriesId);
        self::assertNull($input->typedNumber);
        self::assertSame(self::PARTY, $input->customerId);
        self::assertNull($input->customerOnDocument);
        self::assertSame('2026-11-01', $input->issueDate->format('Y-m-d'));
        self::assertSame('2026-11-02', $input->taxPointDate?->format('Y-m-d'));
        self::assertSame('2026-11-15', $input->dueDate?->format('Y-m-d'));
        self::assertSame(PaymentMethod::BankTransfer, $input->paymentMethod);
        self::assertSame('CZ6508000000192000145399', $input->bankAccount);
        self::assertSame('20260001', $input->variableSymbol);
        self::assertSame('0308', $input->constantSymbol);
        self::assertSame('PO-1', $input->orderNumber);
        self::assertSame('Consulting', $input->description);
        self::assertSame('Before', $input->textBefore);
        self::assertSame('After', $input->textAfter);
        self::assertSame(DocumentLanguage::English, $input->language);
        self::assertSame(3, $submission->version);
        self::assertCount(1, $input->lines);
        $line = $input->lines[0];
        self::assertSame('Work', $line->text);
        self::assertSame('2.5000', $line->quantity->toDecimal());
        self::assertSame('h', $line->unit);
        self::assertSame(120050, $line->price->minor());
        self::assertSame(Currency::CZK, $line->price->currency());
        self::assertSame(PriceType::WithVat, $line->priceType);
        self::assertSame(TaxTreatment::Standard, $line->treatment);
        self::assertSame(VatRateKind::Reduced, $line->rateKind);
        self::assertSame('10.00', $line->discount->decimal());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function prices(): iterable
    {
        yield 'comma' => ['1200,5', 120050];
        yield 'dot' => ['1200.50', 120050];
        yield 'spaces' => ['1 200,50', 120050];
        yield 'no-break spaces' => ["1\u{a0}200,50", 120050];
        yield 'negative' => ['-100,00', -10000];
        yield 'empty is zero' => ['', 0];
    }

    #[DataProvider('prices')]
    public function testAcceptsPricesAsPeopleWriteThem(string $typed, int $minor): void
    {
        $submission = $this->read(['lines' => [['price' => $typed]]]);

        self::assertSame([], $submission->errors);
        self::assertSame($minor, $submission->input->lines[0]->price->minor());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedCells(): iterable
    {
        yield 'a word for a price' => ['price', 'abc', 'invoice.problem.price_invalid'];
        yield 'a third decimal is never rounded' => ['price', '1,005', 'invoice.problem.price_invalid'];
        yield 'a price too long' => ['price', '99999999999999', 'invoice.problem.price_invalid'];
        yield 'a word for a quantity' => ['quantity', 'x', 'invoice.problem.quantity_invalid'];
        yield 'a fifth decimal' => ['quantity', '1,23456', 'invoice.problem.quantity_invalid'];
        yield 'discount over 100' => ['discount', '101', 'invoice.problem.discount_invalid'];
        yield 'a negative discount' => ['discount', '-1', 'invoice.problem.discount_invalid'];
        yield 'an unknown VAT cell' => ['vat', 'nine', 'invoice.problem.vat_invalid'];
        yield 'no VAT cell' => ['vat', '', 'invoice.problem.vat_invalid'];
        yield 'an unknown price type' => ['priceType', 'free', 'invoice.problem.price_type_invalid'];
    }

    #[DataProvider('refusedCells')]
    public function testARefusedCellIsAFormErrorOfThatCell(string $cell, string $value, string $key): void
    {
        $submission = $this->read(['lines' => [[$cell => $value]]]);

        self::assertSame($key, $submission->errors['lines.0.'.$cell] ?? null);
        self::assertSame([$value], array_column($this->rowsOf($submission), $cell));
    }

    public function testQuantityEmptyIsOne(): void
    {
        self::assertSame('1.0000', $this->read(['lines' => [['quantity' => '']]])->input->lines[0]->quantity->toDecimal());
    }

    public function testADiscountMayUseAComma(): void
    {
        self::assertSame('12.50', $this->read(['lines' => [['discount' => '12,5']]])->input->lines[0]->discount->decimal());
    }

    /**
     * @return iterable<string, array{string, TaxTreatment, ?VatRateKind}>
     */
    public static function vatCells(): iterable
    {
        yield 'base' => ['base', TaxTreatment::Standard, VatRateKind::Base];
        yield 'reduced' => ['reduced', TaxTreatment::Standard, VatRateKind::Reduced];
        yield 'reverse charge' => ['reverse_charge', TaxTreatment::ReverseCharge, null];
        yield 'exempt' => ['exempt', TaxTreatment::Exempt, null];
        yield 'a non-payer form' => ['none', TaxTreatment::NotApplicable, null];
    }

    #[DataProvider('vatCells')]
    public function testTheVatCellGivesTreatmentAndRate(string $cell, TaxTreatment $treatment, ?VatRateKind $kind): void
    {
        $line = $this->read(['lines' => [['vat' => $cell]]])->input->lines[0];

        self::assertSame($treatment, $line->treatment);
        self::assertSame($kind, $line->rateKind);
    }

    public function testTheSupplyCodeTravelsWithTheLine(): void
    {
        self::assertSame('4', $this->read(['lines' => [['vat' => 'reverse_charge', 'supplyCode' => '4']]])->input->lines[0]->supplyCode);
        self::assertNull($this->read(['lines' => [['supplyCode' => '']]])->input->lines[0]->supplyCode);
    }

    public function testEmptyLinesAreLeftOut(): void
    {
        $blank = ['text' => '', 'price' => '', 'discount' => '', 'unit' => ''];

        $submission = $this->read(['lines' => [$blank, ['text' => 'Second'], $blank, ['text' => 'Fourth']]]);

        self::assertSame(['Second', 'Fourth'], array_map(static fn (LineInput $line): string => $line->text, $submission->input->lines));
        self::assertSame(['Second', 'Fourth'], array_column($this->rowsOf($submission), 'text'));
    }

    public function testALineWithAPriceButNoTextStays(): void
    {
        self::assertCount(1, $this->read(['lines' => [['text' => '', 'price' => '100']]])->input->lines);
    }

    public function testOneEmptyLineStaysSoTheFormHasARow(): void
    {
        $submission = $this->read(['lines' => [['text' => '', 'price' => '', 'discount' => '', 'unit' => '']]]);

        self::assertCount(1, $submission->input->lines);
        self::assertCount(1, $this->rowsOf($submission));
    }

    public function testNoLinesAtAllIsNoRow(): void
    {
        self::assertSame([], $this->post(['lines' => null])->input->lines);
    }

    public function testADescriptionDefaultsToTheFirstLine(): void
    {
        $submission = $this->read(['description' => '  ', 'lines' => [['text' => 'First'], ['text' => 'Second']]]);

        self::assertSame('First', $submission->input->description);
        self::assertSame('First', $submission->values['description']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fields(): iterable
    {
        yield 'text before' => ['textBefore'];
        yield 'text after' => ['textAfter'];
        yield 'description' => ['description'];
    }

    #[DataProvider('fields')]
    public function testATextTooLongIsRefusedAndNeverCut(string $field): void
    {
        $text = str_repeat('x', InvoiceFormReader::MAX_TEXT + 1);

        $submission = $this->read([$field => $text]);

        self::assertSame('invoice.problem.too_long', $submission->errors[$field]);
        self::assertSame($text, $submission->values[$field]);
    }

    public function testATextAtTheLimitPasses(): void
    {
        self::assertArrayNotHasKey('textBefore', $this->read(['textBefore' => str_repeat('x', InvoiceFormReader::MAX_TEXT)])->errors);
    }

    public function testStrictFieldsReachTheRulesUncut(): void
    {
        $input = $this->read(['constantSymbol' => '12345678901', 'orderNumber' => str_repeat('o', 65), 'variableSymbol' => '12345678901'])->input;

        self::assertSame('12345678901', $input->constantSymbol);
        self::assertSame(str_repeat('o', 65), $input->orderNumber);
        self::assertSame('12345678901', $input->variableSymbol);
    }

    public function testADifferentNumberIsTyped(): void
    {
        self::assertSame('2026-77', $this->read(['number' => ' 2026-77 '])->input->typedNumber);
    }

    public function testThePreviewAndAnEmptyNumberAreNotTyped(): void
    {
        self::assertNull($this->read(['number' => '20260001', 'numberPeek' => '20260001'])->input->typedNumber);
        self::assertNull($this->read(['number' => ''])->input->typedNumber);
    }

    public function testASymbolFromThePreviewFollowsTheRealNumber(): void
    {
        self::assertTrue($this->read(['numberPeek' => '20260001', 'variableSymbol' => '20260001'])->input->variableSymbolFollowsNumber);
        self::assertFalse($this->read(['numberPeek' => '20260001', 'variableSymbol' => '777'])->input->variableSymbolFollowsNumber);
    }

    public function testAnEmptyPreviewFollowsNothing(): void
    {
        self::assertFalse($this->read(['numberPeek' => '', 'number' => '', 'variableSymbol' => ''])->input->variableSymbolFollowsNumber);
    }

    public function testAnIdThatIsNoIdFindsNothing(): void
    {
        $input = $this->read(['customerId' => 'x', 'seriesId' => 'y'])->input;

        self::assertSame(InvoiceFormReader::NO_ID, $input->customerId);
        self::assertSame(InvoiceFormReader::NO_ID, $input->seriesId);
    }

    public function testAnEmptySeriesIsTheDefault(): void
    {
        self::assertNull($this->read(['seriesId' => ''])->input->seriesId);
    }

    public function testAnEmptyBankAccountIsNone(): void
    {
        self::assertNull($this->read(['bankAccount' => ''])->input->bankAccount);
    }

    public function testADateMustExist(): void
    {
        $submission = $this->read(['dueDate' => '2026-02-31', 'taxPointDate' => 'tomorrow']);

        self::assertSame('invoice.problem.date_invalid', $submission->errors['dueDate']);
        self::assertSame('invoice.problem.date_invalid', $submission->errors['taxPointDate']);
    }

    public function testEmptyDatesAreLeftToTheRules(): void
    {
        $submission = $this->read(['dueDate' => '', 'taxPointDate' => '']);

        self::assertSame([], $submission->errors);
        self::assertNull($submission->input->dueDate);
        self::assertNull($submission->input->taxPointDate);
    }

    public function testAnIssueDateIsNeeded(): void
    {
        self::assertSame('invoice.problem.issue_date_missing', $this->read(['issueDate' => ''])->errors['issueDate']);
        self::assertSame('invoice.problem.date_invalid', $this->read(['issueDate' => '01.11.2026'])->errors['issueDate']);
    }

    public function testAnUnknownPaymentMethodOrLanguageIsAFormError(): void
    {
        $submission = $this->read(['paymentMethod' => 'barter', 'language' => 'xx']);

        self::assertSame('invoice.problem.payment_method_invalid', $submission->errors['paymentMethod']);
        self::assertSame('invoice.problem.language_invalid', $submission->errors['language']);
    }

    public function testTheCustomerOnTheDocumentNeedsTheBox(): void
    {
        self::assertNull($this->read(['customerEdit' => ''])->input->customerOnDocument);

        $copy = $this->read(['customerEdit' => '1'])->input->customerOnDocument;

        self::assertNotNull($copy);
        self::assertSame('Edited a.s.', $copy->name);
        self::assertSame('87654320', $copy->registrationNumber);
        self::assertSame('CZ87654320', $copy->vatId);
        self::assertSame('Street 1', $copy->address->street);
        self::assertSame('City', $copy->address->city);
        self::assertSame('100 00', $copy->address->postalCode);
        self::assertSame('CZ', $copy->address->countryCode);
        self::assertSame('a@example.com', $copy->email);
        self::assertSame('+420 000', $copy->phone);
        self::assertSame('example.com', $copy->web);
        self::assertSame('Entry', $copy->registerEntry);
        self::assertNull($copy->logo);
        self::assertNull($copy->stamp);
    }

    public function testEmptyIdentifiersOfTheCopyAreNone(): void
    {
        $copy = $this->read(['customerEdit' => '1', 'customer' => ['registrationNumber' => '', 'vatId' => ' ', 'email' => '']])->input->customerOnDocument;

        self::assertNull($copy?->registrationNumber);
        self::assertNull($copy?->vatId);
        self::assertNull($copy?->email);
    }

    public function testBadShapesCountAsEmpty(): void
    {
        $submission = $this->post(['firmId' => ['x'], 'lines' => 'text', 'customer' => 'text']);

        self::assertSame('', $submission->values['firmId']);
        self::assertSame([], $submission->input->lines);
    }

    public function testALineThatIsNoListIsLeftOut(): void
    {
        self::assertSame(['Real'], array_column($this->rowsOf($this->post(['lines' => ['text', ['text' => 'Real', 'vat' => 'base', 'priceType' => 'without_vat']]])), 'text'));
    }

    public function testAMissingVersionIsNone(): void
    {
        self::assertSame(0, $this->post(['version' => null])->version);
    }

    public function testTheLinesAloneCanBeRead(): void
    {
        $lines = new InvoiceFormReader()->lines(Request::create('/', 'POST', ['lines' => [['text' => '', 'price' => '100', 'vat' => 'base', 'priceType' => 'with_vat']]]), Currency::CZK);

        self::assertSame([], $lines->errors);
        self::assertCount(1, $lines->lines);
    }

    public function testCountingKeepsEmptyLinesSoRowsMatch(): void
    {
        $blank = ['text' => '', 'price' => '', 'vat' => 'base', 'priceType' => 'without_vat'];

        self::assertCount(2, new InvoiceFormReader()->lines(Request::create('/', 'POST', ['lines' => [$blank, $blank]]), Currency::CZK)->lines);
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowsOf(InvoiceSubmission $submission): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $submission->values['lines'];

        return $rows;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function read(array $changes): InvoiceSubmission
    {
        /** @var list<array<string, string>> $lines */
        $lines = $changes['lines'] ?? [[]];
        $changes['lines'] = array_map(static fn (array $line): array => array_merge(['text' => 'Work', 'price' => '1200,50', 'quantity' => '2,5', 'unit' => 'h', 'priceType' => 'with_vat', 'vat' => 'reduced', 'supplyCode' => '', 'discount' => '10'], $line), $lines);
        if (\array_key_exists('customer', $changes) && \is_array($changes['customer'])) {
            $changes['customer'] = array_merge(self::CUSTOMER, $changes['customer']);
        }

        return $this->post($changes);
    }

    private const array CUSTOMER = ['name' => 'Edited a.s.', 'registrationNumber' => '87654320', 'vatId' => 'CZ87654320', 'street' => 'Street 1', 'city' => 'City', 'postalCode' => '100 00', 'countryCode' => 'CZ', 'email' => 'a@example.com', 'phone' => '+420 000', 'web' => 'example.com', 'registerEntry' => 'Entry'];

    /**
     * @param array<string, mixed> $changes
     */
    private function post(array $changes): InvoiceSubmission
    {
        $post = array_merge([
            'firmId' => self::FIRM, 'seriesId' => self::SERIES, 'number' => '20260001', 'numberPeek' => '20260001', 'customerId' => self::PARTY, 'customer' => self::CUSTOMER,
            'issueDate' => '2026-11-01', 'taxPointDate' => '2026-11-02', 'dueDate' => '2026-11-15', 'paymentMethod' => 'bank_transfer', 'bankAccount' => 'CZ6508000000192000145399',
            'variableSymbol' => '20260001', 'constantSymbol' => '0308', 'orderNumber' => 'PO-1', 'description' => 'Consulting', 'textBefore' => 'Before', 'textAfter' => 'After',
            'language' => 'en', 'version' => '3',
        ], $changes);

        return new InvoiceFormReader()->read(Request::create('/', 'POST', array_filter($post, static fn (mixed $value): bool => null !== $value)), Currency::CZK);
    }
}
