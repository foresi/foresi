<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Domain\AuditEntry;
use App\Invoicing\Application\CopyInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesCounter;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * A source from last year, copied on 10 February 2027.
 */
final class CopyInvoiceTest extends InvoiceTestCase
{
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

    private CopyInvoice $copy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copy = self::getContainer()->get(CopyInvoice::class);
        $this->firmChanged(VatStatus::Registered, [self::IBAN, self::SECOND_IBAN]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testNumberAndDatesStartOver(): void
    {
        $source = $this->source();
        $this->customer->changeInvoicingDefaults(new InvoicingDefaults(21, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false));
        $this->em->flush();

        $draft = $this->copy->valuesFrom($source);

        self::assertSame('20270001', $draft->number);
        self::assertSame('20270001', $draft->variableSymbol);
        self::assertSame('2027-02-10', $draft->issueDate->format('Y-m-d'));
        self::assertSame('2027-02-10', $draft->taxPointDate->format('Y-m-d'));
        self::assertSame('2027-03-03', $draft->dueDate->format('Y-m-d'));
    }

    public function testWhatTheSourceSaysStays(): void
    {
        $source = $this->source();

        $draft = $this->copy->valuesFrom($source);

        self::assertSame($this->firm->id(), $draft->firmId);
        self::assertSame($this->series->id(), $draft->seriesId);
        self::assertSame($this->customer->id(), $draft->customerId);
        self::assertSame('Consulting', $draft->description);
        self::assertSame('0308', $draft->constantSymbol);
        self::assertSame('Before', $draft->textBefore);
        self::assertSame('After', $draft->textAfter);
        self::assertSame(DocumentLanguage::English, $draft->language);
        self::assertSame(PaymentMethod::Cash, $draft->paymentMethod);
        self::assertSame(self::IBAN, $draft->bankAccount);
        self::assertSame(VatRegime::Payer, $draft->regime);
        self::assertSame(Currency::CZK, $draft->currency);
        self::assertSame([], $draft->notices);
        self::assertCount(2, $draft->lines);
        $first = $draft->lines[0];
        self::assertSame('First', $first->text);
        self::assertSame('2.5', rtrim(rtrim($first->quantity->toDecimal(), '0'), '.'));
        self::assertSame('ks', $first->unit);
        self::assertSame(12345, $first->price->minor());
        self::assertSame(PriceType::WithVat, $first->priceType);
        self::assertSame(TaxTreatment::Standard, $first->treatment);
        self::assertSame(VatRateKind::Reduced, $first->rateKind);
        self::assertSame(1000, $first->discount->basisPoints());
        self::assertSame('Second', $draft->lines[1]->text);
    }

    public function testAnotherSeriesOfTheFirmStaysWithItsOwnNumber(): void
    {
        $second = new Series($this->firm, 'Second', SeriesFormat::fromString('S{YYYY}{NNN}'), DocumentKind::Invoice);
        $this->em->persist($second);
        $this->em->flush();
        $source = $this->source(['seriesId' => $second->id()]);

        $draft = $this->copy->valuesFrom($source);

        self::assertSame($second->id(), $draft->seriesId);
        self::assertSame('S2027001', $draft->number);
        self::assertSame('2027001', $draft->variableSymbol);
    }

    public function testAnArchivedSeriesGivesWayToTheDefaultAndSaysSo(): void
    {
        $second = new Series($this->firm, 'Second', SeriesFormat::fromString('S{YYYY}{NNN}'), DocumentKind::Invoice);
        $this->em->persist($second);
        $this->series->markDefault();
        $this->em->flush();
        $source = $this->source(['seriesId' => $second->id()]);
        $this->reloadedSeries($second)->archive();
        $this->em->flush();

        $draft = $this->copy->valuesFrom($source);

        self::assertSame($this->series->id(), $draft->seriesId);
        self::assertSame('20270001', $draft->number);
        self::assertSame(['invoice.copy.series_archived'], $draft->notices);
    }

    public function testAKeptAccountStaysUntilTheFirmDropsIt(): void
    {
        $source = $this->source(['bankAccount' => self::SECOND_IBAN, 'paymentMethod' => PaymentMethod::BankTransfer]);
        self::assertSame(self::SECOND_IBAN, $this->copy->valuesFrom($source)->bankAccount);

        $this->firmChanged(VatStatus::Registered, [self::IBAN]);

        self::assertSame(self::IBAN, $this->copy->valuesFrom($source)->bankAccount);
    }

    public function testACopyOfAnArchivedFirmOpensAndAsksForAnotherFirm(): void
    {
        $source = $this->source();
        $this->firm->archive(new \DateTimeImmutable('2027-01-01'));
        $this->em->flush();

        $draft = $this->copy->valuesFrom($source);

        self::assertSame(['invoice.copy.firm_archived'], $draft->notices);
        self::assertSame($this->firm->id(), $draft->firmId);
        self::assertSame($this->customer->id(), $draft->customerId);
    }

    public function testACopyForACustomerArchivedForTheFirmWarnsAndNeedsAnotherCustomer(): void
    {
        $source = $this->source();
        self::getContainer()->get(ContactSettingsForFirm::class)->changeArchived($this->customer, $this->firm, true);
        $this->em->flush();

        $draft = $this->copy->valuesFrom($source);

        self::assertNull($draft->customerId);
        self::assertSame(['invoice.copy.customer_archived'], $draft->notices);
        self::assertSame('2027-02-24', $draft->dueDate->format('Y-m-d'));
    }

    public function testACustomerWhoStoppedBeingACustomerAlsoWarns(): void
    {
        $source = $this->source();
        self::getContainer()->get(ContactSettingsForFirm::class)->changeRoles($this->customer, $this->firm, [\App\Party\Domain\PartyRole::Supplier]);
        $this->em->flush();

        $draft = $this->copy->valuesFrom($source);

        self::assertNull($draft->customerId);
        self::assertSame(['invoice.copy.customer_archived'], $draft->notices);
    }

    public function testACopyOfAReverseChargeInvoiceKeepsTreatmentAndCode(): void
    {
        $source = $this->issued([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]);

        $line = $this->copy->valuesFrom($source)->lines[0];

        self::assertSame(TaxTreatment::ReverseCharge, $line->treatment);
        self::assertSame('4', $line->supplyCode);
        self::assertNull($line->rateKind);
    }

    public function testExemptStaysExempt(): void
    {
        $source = $this->issued([$this->line(treatment: TaxTreatment::Exempt, kind: null)]);

        $line = $this->copy->valuesFrom($source)->lines[0];

        self::assertSame(TaxTreatment::Exempt, $line->treatment);
        self::assertNull($line->rateKind);
    }

    public function testANonPayerCopyStaysWithoutVat(): void
    {
        $firm = $this->secondFirm(VatStatus::NotRegistered, null);
        $source = $this->issued([$this->line(treatment: TaxTreatment::NotApplicable, kind: null)], ['firmId' => $firm->id(), 'seriesId' => null, 'bankAccount' => self::IBAN]);

        $draft = $this->copy->valuesFrom($source);

        self::assertSame(VatRegime::NonPayer, $draft->regime);
        self::assertSame(TaxTreatment::NotApplicable, $draft->lines[0]->treatment);
        self::assertNull($draft->lines[0]->rateKind);
    }

    public function testASourceFromBeforeTheFirmStoppedPayingVatComesWithoutVat(): void
    {
        $source = $this->issued([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4'), $this->line(type: PriceType::WithVat)]);
        $this->firmChanged(VatStatus::NotRegistered, [self::IBAN]);

        $draft = $this->copy->valuesFrom($source);

        self::assertSame(VatRegime::NonPayer, $draft->regime);
        foreach ($draft->lines as $line) {
            self::assertSame(TaxTreatment::NotApplicable, $line->treatment);
            self::assertNull($line->rateKind);
            self::assertNull($line->supplyCode);
            self::assertSame(PriceType::WithoutVat, $line->priceType);
        }
    }

    public function testASourceFromBeforeTheFirmBecameAPayerTakesTheBaseRate(): void
    {
        $firm = $this->secondFirm(VatStatus::NotRegistered, null);
        $source = $this->issued([$this->line(treatment: TaxTreatment::NotApplicable, kind: null)], ['firmId' => $firm->id(), 'seriesId' => null]);
        $this->firm = $firm;
        $this->firmChanged(VatStatus::Registered, [self::IBAN], 'CZ12345687');

        $line = $this->copy->valuesFrom($source)->lines[0];

        self::assertSame(TaxTreatment::Standard, $line->treatment);
        self::assertSame(VatRateKind::Base, $line->rateKind);
    }

    public function testATrashedInvoiceCanBeCopied(): void
    {
        $source = $this->source();
        $trashed = $this->trash->trash($source->id(), 'Wrong customer');

        $draft = $this->copy->valuesFrom($trashed);

        self::assertSame('Consulting', $draft->description);
        self::assertCount(2, $draft->lines);
    }

    public function testCopyWritesNothing(): void
    {
        $source = $this->source();
        $counts = $this->counts();

        $this->copy->valuesFrom($source);
        $this->em->flush();

        self::assertSame($counts, $this->counts());
    }

    /**
     * @return list<int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ([Invoice::class, SeriesCounter::class, AuditEntry::class, AbandonedNumber::class] as $class) {
            $counts[] = (int) $this->em->createQuery('SELECT COUNT(e) FROM '.$class.' e')->getSingleScalarResult();
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function source(array $overrides = []): Invoice
    {
        $lines = [
            new LineInput('First', Quantity::fromString('2.5'), 'ks', Money::ofMinor(12345, Currency::CZK), PriceType::WithVat, TaxTreatment::Standard, VatRateKind::Reduced, null, Percent::fromString('10')),
            $this->line(text: 'Second'),
        ];

        return $this->issued($lines, array_merge(['language' => DocumentLanguage::English, 'paymentMethod' => PaymentMethod::Cash], $overrides));
    }

    /**
     * Issued on the date of the input, last year, and the clock moved on after it.
     *
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function issued(array $lines, array $overrides = []): Invoice
    {
        Clock::set(new MockClock('2026-11-01 12:00:00', 'UTC'));
        $invoice = $this->issue->issue($this->input($lines, $overrides));
        Clock::set(new MockClock('2027-02-10 12:00:00', 'UTC'));

        return $invoice;
    }

    /**
     * @param list<string> $accounts
     */
    private function firmChanged(VatStatus $status, array $accounts, ?string $vatId = 'CZ12345695'): void
    {
        $firm = $this->firm;
        $firm->update($firm->name(), $firm->registrationNumber(), VatStatus::Registered === $status ? \App\Party\Domain\VatId::fromString($vatId ?? '') : null, $firm->address(), $status, $firm->registerEntry(), $accounts);
        $this->em->flush();
    }

    private function reloadedSeries(Series $series): Series
    {
        return $this->catalog->get($series->id());
    }

    private function secondFirm(VatStatus $status, ?string $vatId): \App\Party\Domain\LegalEntity
    {
        $firm = $this->firm($status, $vatId, registrationNumber: '12345687');
        $this->em->flush();

        return $firm;
    }
}
