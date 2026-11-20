<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\CustomerOption;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\InvoiceDraftValues;
use Foresi\Invoicing\Application\NewInvoiceDefaults;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\FirmInvoicing;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class NewInvoiceDefaultsTest extends InvoiceTestCase
{
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

    private NewInvoiceDefaults $defaults;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-01 12:00:00', 'UTC'));
        parent::setUp();
        $this->defaults = self::getContainer()->get(NewInvoiceDefaults::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testDefaultsAre14DaysAndToday(): void
    {
        $draft = $this->defaults->for();

        self::assertSame('2026-11-01', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2026-11-01', $draft->taxPointDate->format('Y-m-d'));
        self::assertSame('2026-11-15', $draft->dueDate->format('Y-m-d'));
        self::assertSame(PaymentMethod::BankTransfer, $draft->paymentMethod);
        self::assertSame(self::IBAN, $draft->bankAccount);
        self::assertSame(DocumentLanguage::Czech, $draft->language);
        self::assertSame(Currency::CZK, $draft->currency);
        self::assertSame(VatRegime::Payer, $draft->regime);
        self::assertNull($draft->customerId);
        self::assertNull($draft->currencyNotice);
    }

    public function testAGivenDateIsTheDayOfTheDraft(): void
    {
        $draft = $this->defaults->for(new \DateTimeImmutable('2027-02-10'));

        self::assertSame('2027-02-10', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2027-02-24', $draft->dueDate->format('Y-m-d'));
        self::assertSame('20270001', $draft->number);
    }

    public function testTodayFollowsPragueNotUtc(): void
    {
        Clock::set(new MockClock('2026-12-31 23:30:00', 'UTC'));

        $draft = $this->defaults->for();

        self::assertSame('2027-01-01', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2027-01-15', $draft->dueDate->format('Y-m-d'));
        self::assertSame('20270001', $draft->number);
    }

    public function testTheNumberIsThePeekOfTheDefaultSeries(): void
    {
        $this->series->markDefault();
        $this->em->flush();
        $this->issue->issue($this->input());

        $draft = $this->defaults->for();

        self::assertSame($this->series->id(), $draft->seriesId);
        self::assertSame('20260002', $draft->number);
        self::assertSame('20260002', $draft->variableSymbol);
    }

    public function testASeriesNotStoredYetIsNoSeriesOfTheDraft(): void
    {
        $draft = $this->defaults->for();

        self::assertNull($draft->seriesId);
        self::assertSame('20260001', $draft->number);
    }

    public function testOneEmptyLineAtTheBaseRate(): void
    {
        $lines = $this->defaults->for()->lines;

        self::assertCount(1, $lines);
        self::assertSame('', $lines[0]->text);
        self::assertSame('1.0000', $lines[0]->quantity->toDecimal());
        self::assertSame('', $lines[0]->unit);
        self::assertTrue($lines[0]->price->isZero());
        self::assertSame(PriceType::WithoutVat, $lines[0]->priceType);
        self::assertSame(TaxTreatment::Standard, $lines[0]->treatment);
        self::assertSame(VatRateKind::Base, $lines[0]->rateKind);
        self::assertNull($lines[0]->supplyCode);
        self::assertSame(0, $lines[0]->discount->basisPoints());
    }

    public function testTextsAndConstantSymbolComeFromTheFirm(): void
    {
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $own = FirmInvoicing::standard();
        $own->change(TotalRounding::WholeUnit, '0308', 'Before text', 'After text', '', '', '');
        $settings->save($own);
        $this->em->flush();

        $draft = $this->defaults->for();

        self::assertSame('0308', $draft->constantSymbol);
        self::assertSame('Before text', $draft->textBefore);
        self::assertSame('After text', $draft->textAfter);
    }

    public function testArchivedAccountsAreNotOfferedToInvoices(): void
    {
        $second = FirmAccounts::add($this->em, self::SECOND_IBAN);
        $this->em->flush();
        self::assertSame(self::IBAN, $this->defaults->for()->bankAccount, 'the first account is the primary one');

        $this->em->wrapInTransaction(function () use ($second): void {
            $accounts = self::getContainer()->get(FirmBankAccounts::class);
            $accounts->makePrimary($second);
        });
        $this->em->clear();
        self::assertSame(self::SECOND_IBAN, $this->defaults->for()->bankAccount, 'a new invoice gets the primary account');

        FirmAccounts::archiveAll($this->em);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->defaults->for()->bankAccount, 'an archived account is offered to no invoice');
    }

    public function testAFirmWithoutAccountsHasNoBankAccount(): void
    {
        FirmAccounts::archiveAll($this->em);
        $this->em->flush();

        self::assertNull($this->defaults->for()->bankAccount);
    }

    public function testAFirmOfAnUnsupportedCountryHasNoDefaults(): void
    {
        $this->reconfigureFirm(VatStatus::NotRegistered, null, 'Register', new Address('Example Street 1', 'Example City', '100 00', 'SK'));

        $this->expectException(UnsupportedCountry::class);
        $this->defaults->for();
    }

    public function testANonPayerFirmGetsNoRates(): void
    {
        $this->reconfigureFirm(VatStatus::NotRegistered, null);

        $draft = $this->defaults->for();

        self::assertSame(VatRegime::NonPayer, $draft->regime);
        self::assertSame(TaxTreatment::NotApplicable, $draft->lines[0]->treatment);
        self::assertNull($draft->lines[0]->rateKind);
    }

    public function testAnIdentifiedPersonIssuesWithoutVat(): void
    {
        $this->reconfigureFirm(VatStatus::IdentifiedPerson, 'CZ12345695');

        self::assertSame(TaxTreatment::NotApplicable, $this->defaults->for()->lines[0]->treatment);
    }

    public function testACustomerSetsDueDatePaymentAndLanguage(): void
    {
        $draft = $this->defaults->withCustomer(
            $this->defaults->for(),
            $this->option(new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::English, PaymentMethod::Cash, false)),
        );

        self::assertSame($this->customer->id(), $draft->customerId);
        self::assertSame('2026-12-01', $draft->dueDate->format('Y-m-d'));
        self::assertSame(PaymentMethod::Cash, $draft->paymentMethod);
        self::assertSame(DocumentLanguage::English, $draft->language);
        self::assertSame(TaxTreatment::Standard, $draft->lines[0]->treatment);
        self::assertSame('2026-11-01', $draft->issueDate->format('Y-m-d'));
        self::assertSame('20260001', $draft->number);
    }

    public function testTheDueDateCountsFromTheDateOnTheForm(): void
    {
        $draft = $this->defaults->for()->onIssueDate(new \DateTimeImmutable('2026-10-10'));

        self::assertSame('2026-10-24', $this->defaults->withCustomer($draft, $this->option(InvoicingDefaults::standard()))->dueDate->format('Y-m-d'));
    }

    public function testReverseChargeFromTheContactFillsLines(): void
    {
        $draft = $this->defaults->withCustomer($this->twoLines(), $this->option(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true)));

        foreach ($draft->lines as $line) {
            self::assertSame(TaxTreatment::ReverseCharge, $line->treatment);
            self::assertNull($line->rateKind);
        }
    }

    public function testAnotherCustomerTakesReverseChargeBack(): void
    {
        $charged = $this->defaults->withCustomer($this->twoLines(), $this->option(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true)));

        $draft = $this->defaults->withCustomer($charged, $this->option(InvoicingDefaults::standard()));

        self::assertSame(TaxTreatment::Standard, $draft->lines[1]->treatment);
        self::assertSame(VatRateKind::Base, $draft->lines[1]->rateKind);
    }

    public function testACustomerLeavesOtherLinesAlone(): void
    {
        $draft = $this->defaults->for();
        $exempt = $this->line(treatment: TaxTreatment::Exempt, kind: null);

        $picked = $this->defaults->withCustomer($draft->withLines([$exempt]), $this->option(InvoicingDefaults::standard()));

        self::assertSame(TaxTreatment::Exempt, $picked->lines[0]->treatment);
        self::assertSame('Work', $picked->lines[0]->text);
    }

    public function testANonPayerIgnoresReverseChargeOfTheContact(): void
    {
        $this->reconfigureFirm(VatStatus::NotRegistered, null);

        $draft = $this->defaults->withCustomer($this->defaults->for(), $this->option(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true)));

        self::assertSame(TaxTreatment::NotApplicable, $draft->lines[0]->treatment);
    }

    public function testAForeignCurrencyContactKeepsHomeCurrencyWithANotice(): void
    {
        $draft = $this->defaults->withCustomer(
            $this->defaults->for(),
            $this->option(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)),
        );

        self::assertSame(Currency::CZK, $draft->currency);
        self::assertSame(Currency::EUR, $draft->currencyNotice);
    }

    public function testAHomeCurrencyContactClearsTheNotice(): void
    {
        $foreign = $this->defaults->withCustomer(
            $this->defaults->for(),
            $this->option(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)),
        );

        self::assertNull($this->defaults->withCustomer($foreign, $this->option(InvoicingDefaults::standard()))->currencyNotice);
    }

    private function twoLines(): InvoiceDraftValues
    {
        return $this->defaults->for()->withLines([$this->line(), $this->line(kind: VatRateKind::Reduced)]);
    }

    private function option(InvoicingDefaults $defaults): CustomerOption
    {
        return new CustomerOption($this->customer->id(), 'Example Customer a.s.', '87654320', 'CZ87654320', new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), $defaults, 'billing@example.com');
    }
}
