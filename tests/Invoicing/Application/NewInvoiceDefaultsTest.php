<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\CustomerOption;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceDraftValues;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class NewInvoiceDefaultsTest extends InvoiceTestCase
{
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
        $draft = $this->defaults->for($this->firm);

        self::assertSame('2026-11-01', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2026-11-01', $draft->taxPointDate->format('Y-m-d'));
        self::assertSame('2026-11-15', $draft->dueDate->format('Y-m-d'));
        self::assertSame(PaymentMethod::BankTransfer, $draft->paymentMethod);
        self::assertSame(self::IBAN, $draft->bankAccount);
        self::assertSame(DocumentLanguage::Czech, $draft->language);
        self::assertSame(Currency::CZK, $draft->currency);
        self::assertSame($this->firm->id(), $draft->firmId);
        self::assertSame(VatRegime::Payer, $draft->regime);
        self::assertNull($draft->customerId);
        self::assertNull($draft->currencyNotice);
    }

    public function testAGivenDateIsTheDayOfTheDraft(): void
    {
        $draft = $this->defaults->for($this->firm, new \DateTimeImmutable('2027-02-10'));

        self::assertSame('2027-02-10', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2027-02-24', $draft->dueDate->format('Y-m-d'));
        self::assertSame('20270001', $draft->number);
    }

    public function testTodayFollowsPragueNotUtc(): void
    {
        Clock::set(new MockClock('2026-12-31 23:30:00', 'UTC'));

        $draft = $this->defaults->for($this->firm);

        self::assertSame('2027-01-01', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2027-01-15', $draft->dueDate->format('Y-m-d'));
        self::assertSame('20270001', $draft->number);
    }

    public function testTheNumberIsThePeekOfTheDefaultSeries(): void
    {
        $this->series->markDefault();
        $this->em->flush();
        $this->issue->issue($this->input());

        $draft = $this->defaults->for($this->firm);

        self::assertSame($this->series->id(), $draft->seriesId);
        self::assertSame('20260002', $draft->number);
        self::assertSame('20260002', $draft->variableSymbol);
    }

    public function testASeriesNotStoredYetIsNoSeriesOfTheDraft(): void
    {
        $firm = $this->firm(registrationNumber: '12345687');
        $this->em->flush();

        $draft = $this->defaults->for($firm);

        self::assertNull($draft->seriesId);
        self::assertSame('20260001', $draft->number);
    }

    public function testOneEmptyLineAtTheBaseRate(): void
    {
        $lines = $this->defaults->for($this->firm)->lines;

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
        $own = FirmInvoicing::standard($this->firm);
        $own->change(TotalRounding::WholeUnit, '0308', 'Before text', 'After text', '', '', '');
        $settings->save($own);
        $this->em->flush();

        $draft = $this->defaults->for($this->firm);

        self::assertSame('0308', $draft->constantSymbol);
        self::assertSame('Before text', $draft->textBefore);
        self::assertSame('After text', $draft->textAfter);
    }

    public function testAFirmWithoutAccountsHasNoBankAccount(): void
    {
        $firm = new LegalEntity('Example Bankless s.r.o.', new RegistrationNumber('CZ', '12345687'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', []);
        $this->em->persist($firm);
        $this->em->flush();

        self::assertNull($this->defaults->for($firm)->bankAccount);
    }

    public function testAFirmOfAnUnsupportedCountryHasNoDefaults(): void
    {
        $firm = new LegalEntity('Example Foreign', new RegistrationNumber('CZ', '12345687'), null, new Address('Example Street 1', 'Example City', '100 00', 'SK'), VatStatus::NotRegistered, 'Register', []);
        $this->em->persist($firm);
        $this->em->flush();

        $this->expectException(UnsupportedCountry::class);
        $this->defaults->for($firm);
    }

    public function testANonPayerFirmGetsNoRates(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();

        $draft = $this->defaults->for($firm);

        self::assertSame(VatRegime::NonPayer, $draft->regime);
        self::assertSame(TaxTreatment::NotApplicable, $draft->lines[0]->treatment);
        self::assertNull($draft->lines[0]->rateKind);
    }

    public function testAnIdentifiedPersonIssuesWithoutVat(): void
    {
        $firm = $this->firm(VatStatus::IdentifiedPerson, registrationNumber: '12345687');
        $this->em->flush();

        self::assertSame(TaxTreatment::NotApplicable, $this->defaults->for($firm)->lines[0]->treatment);
    }

    public function testTheLastUsedFirmIsTheDefault(): void
    {
        $user = 'a0000000-0000-7000-8000-000000000001';
        $first = $this->namedFirm('A Firm', '12345687');
        $used = $this->namedFirm('B Firm', '12345679');
        $this->em->flush();

        self::assertSame($first->id(), $this->defaults->firmFor($user)?->id());
        self::assertSame($first->id(), $this->defaults->firmFor(null)?->id());

        $store = self::createStub(\App\Invoicing\Application\InvoiceStore::class);
        $store->method('lastFirmOf')->willReturnMap([[$user, $used]]);
        $defaults = new NewInvoiceDefaults(
            $store,
            self::getContainer()->get(\App\Party\Application\LegalEntities::class),
            $this->catalog,
            $this->counter,
            self::getContainer()->get(FirmInvoicingSettings::class),
            self::getContainer()->get(\App\Invoicing\Application\InvoicingCountries::class),
            self::getContainer()->get(\Psr\Clock\ClockInterface::class),
        );

        self::assertSame($used->id(), $defaults->firmFor($user)?->id());
    }

    public function testNoFirmNoDefault(): void
    {
        $this->firm->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        self::assertNull($this->defaults->firmFor(null));
    }

    public function testACustomerSetsDueDatePaymentAndLanguage(): void
    {
        $draft = $this->defaults->withCustomer(
            $this->defaults->for($this->firm),
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
        $draft = $this->defaults->for($this->firm)->onIssueDate(new \DateTimeImmutable('2026-10-10'));

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
        $draft = $this->defaults->for($this->firm);
        $exempt = $this->line(treatment: TaxTreatment::Exempt, kind: null);

        $picked = $this->defaults->withCustomer($draft->withLines([$exempt]), $this->option(InvoicingDefaults::standard()));

        self::assertSame(TaxTreatment::Exempt, $picked->lines[0]->treatment);
        self::assertSame('Work', $picked->lines[0]->text);
    }

    public function testANonPayerIgnoresReverseChargeOfTheContact(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();

        $draft = $this->defaults->withCustomer($this->defaults->for($firm), $this->option(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true)));

        self::assertSame(TaxTreatment::NotApplicable, $draft->lines[0]->treatment);
    }

    public function testAForeignCurrencyContactKeepsHomeCurrencyWithANotice(): void
    {
        $draft = $this->defaults->withCustomer(
            $this->defaults->for($this->firm),
            $this->option(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)),
        );

        self::assertSame(Currency::CZK, $draft->currency);
        self::assertSame(Currency::EUR, $draft->currencyNotice);
    }

    public function testAHomeCurrencyContactClearsTheNotice(): void
    {
        $foreign = $this->defaults->withCustomer(
            $this->defaults->for($this->firm),
            $this->option(new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false)),
        );

        self::assertNull($this->defaults->withCustomer($foreign, $this->option(InvoicingDefaults::standard()))->currencyNotice);
    }

    private function twoLines(): InvoiceDraftValues
    {
        return $this->defaults->for($this->firm)->withLines([$this->line(), $this->line(kind: VatRateKind::Reduced)]);
    }

    private function option(InvoicingDefaults $defaults): CustomerOption
    {
        return new CustomerOption($this->customer->id(), 'Example Customer a.s.', '87654320', 'CZ87654320', new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), $defaults, 'billing@example.com');
    }

    private function namedFirm(string $name, string $number): LegalEntity
    {
        $this->firm->archive(new \DateTimeImmutable('2026-10-01'));
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', []);
        $this->em->persist($firm);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->em->persist($series);

        return $firm;
    }
}
