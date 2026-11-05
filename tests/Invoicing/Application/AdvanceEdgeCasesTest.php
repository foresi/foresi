<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Banking\Application\Candidates;
use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\AdvanceTaxation;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\CorrectPaymentTaxDocument;
use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\DocumentChain;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssuePaymentTaxDocument;
use App\Invoicing\Application\MissingTaxDocuments;
use App\Invoicing\Application\PaymentSourceRelease;
use App\Invoicing\Application\PaymentTaxDocumentTexts;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\TaxOnReceipt;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\MailTemplateKind;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatId;
use App\Tests\Banking\Support\LockLog;
use App\Tests\Banking\Support\LoggingInvoiceStore;
use App\Tests\Party\Support\FirmAccounts;
use App\Tests\Support\IsdocSchema;
use Symfony\Component\Clock\Clock;

/**
 * The corners of advances: how lines group, which account a tax document names,
 * documents of several rates and treatments, and what each read model leaves out.
 */
final class AdvanceEdgeCasesTest extends AdvanceTestCase
{
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

    public function testLinesGroupByTreatmentRateAndSupplyCode(): void
    {
        $advance = $this->advance([
            $this->line('1000.00'),
            $this->line('500.00', treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4'),
            $this->line('300.00', treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '1a'),
            $this->line('200.00', treatment: TaxTreatment::Exempt, kind: null),
            $this->line('100.00', kind: VatRateKind::Reduced),
            $this->line('-100.00', kind: VatRateKind::Reduced),
            $this->line('-50.00', treatment: TaxTreatment::Exempt, kind: null, text: 'Discount'),
        ]);

        $this->pay($advance, '1000.00');

        $lines = $this->store->lines($this->onlyTaxDocumentOf($advance));
        self::assertSame(
            [[TaxTreatment::Standard, '21.00', null], [TaxTreatment::ReverseCharge, null, '4'], [TaxTreatment::ReverseCharge, null, '1a'], [TaxTreatment::Exempt, null, null]],
            array_map(static fn (\App\Invoicing\Domain\InvoiceLine $line): array => [$line->treatment(), $line->rate()?->decimal(), $line->supplyCode()], $lines),
            'the reduced group adds up to nothing and takes no share',
        );
        self::assertSame(100000, array_sum(array_map(static fn (\App\Invoicing\Domain\InvoiceLine $line): int => $line->gross()->minor(), $lines)));
    }

    public function testAForeignCustomersReverseChargeAndExemptLinesStayApart(): void
    {
        $advance = $this->advance([$this->line('600.00', treatment: TaxTreatment::ReverseCharge, kind: null), $this->line('400.00', treatment: TaxTreatment::Exempt, kind: null)], ['customerId' => $this->german()->id()]);
        $this->pay($advance, '1000.00');
        $document = $this->onlyTaxDocumentOf($advance);
        self::assertSame([[TaxTreatment::ReverseCharge, 60000], [TaxTreatment::Exempt, 40000]], array_map(static fn (\App\Invoicing\Domain\InvoiceLine $line): array => [$line->treatment(), $line->gross()->minor()], $this->store->lines($document)));

        $deduction = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $advance->customerParty()->id(), null, DocumentLanguage::English)[0]->lines;
        $final = $this->issue->issue($this->input([$this->line('3000.00', treatment: TaxTreatment::ReverseCharge, kind: null), $this->line('500.00', treatment: TaxTreatment::Exempt, kind: null), ...$deduction], ['customerId' => $advance->customerParty()->id()]));
        $file = $this->czech()->electronicFile(self::getContainer()->get(IssuedDocumentFactory::class)->of($final->id()));
        self::assertNotNull($file);

        IsdocSchema::assertValid($file->content);
        self::assertSame(2, substr_count($file->content, '<TaxedDeposit>'));
        self::assertStringContainsString('<TaxableDepositAmount>600.00</TaxableDepositAmount>', $file->content);
        self::assertStringContainsString('<TaxableDepositAmount>400.00</TaxableDepositAmount>', $file->content);
        self::assertSame(2, preg_match_all('#<TaxedDeposit>(?:(?!</TaxedDeposit>).)*<Percent>0\.00</Percent>\s*<VATCalculationMethod>1</VATCalculationMethod>\s*<VATApplicable>false</VATApplicable>#s', $file->content));
    }

    public function testATwoRateTaxDocumentIsADepositPerRate(): void
    {
        $advance = $this->advance([$this->gross('1210.00'), $this->gross('1120.00', VatRateKind::Reduced)]);
        $this->pay($advance, '2330.00');
        $deduction = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech)[0]->lines;
        $final = $this->issue->issue($this->input([$this->line('5000.00'), $this->line('5000.00', kind: VatRateKind::Reduced), ...$deduction]));

        $file = $this->czech()->electronicFile(self::getContainer()->get(IssuedDocumentFactory::class)->of($final->id()));

        self::assertNotNull($file);
        self::assertSame(2, substr_count($file->content, '<TaxedDeposit>'));
        self::assertSame(1, preg_match_all('#<TaxedDeposit>(?:(?!</TaxedDeposit>).)*<Percent>21\.00</Percent>\s*<VATCalculationMethod>1</VATCalculationMethod>\s*<VATApplicable>true</VATApplicable>#s', $file->content));
        self::assertSame(1, preg_match_all('#<TaxedDeposit>(?:(?!</TaxedDeposit>).)*<Percent>12\.00</Percent>\s*<VATCalculationMethod>1</VATCalculationMethod>\s*<VATApplicable>true</VATApplicable>#s', $file->content));
    }

    public function testTheTaxDocumentKeepsTheAdvancesAccountOrTakesThePrimary(): void
    {
        FirmAccounts::add($this->em, $this->firm, self::SECOND_IBAN);
        $this->em->flush();
        $onSecond = $this->advance([$this->line('1000.00')], ['bankAccount' => self::SECOND_IBAN]);
        $byCash = $this->advance([$this->line('1000.00')], ['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null]);

        $this->pay($onSecond, '1210.00');
        $this->pay($byCash, '1210.00');

        self::assertSame(self::SECOND_IBAN, $this->onlyTaxDocumentOf($onSecond)->bankAccount());
        self::assertSame(self::IBAN, $this->onlyTaxDocumentOf($byCash)->bankAccount());
    }

    public function testAnArchivedAccountOfTheAdvanceGivesWayToThePrimary(): void
    {
        FirmAccounts::add($this->em, $this->firm, self::SECOND_IBAN);
        $this->em->flush();
        $advance = $this->advance([$this->line('1000.00')], ['bankAccount' => self::SECOND_IBAN]);
        $this->em->getConnection()->executeStatement('UPDATE firm_bank_account SET archived_at = CURRENT_TIMESTAMP WHERE iban = ?', [self::SECOND_IBAN]);

        $this->pay($advance, '1210.00');

        self::assertSame(self::IBAN, $this->onlyTaxDocumentOf($advance)->bankAccount());
    }

    public function testOnlyAnAdvanceGetsATaxDocumentWhenAsked(): void
    {
        $invoice = $this->issue->issue($this->input());
        $payment = $this->pay($invoice, '1210.00');

        self::assertNull($this->taxDocuments()->forPayment($payment, $invoice->id(), $this->czk('1210.00')));
    }

    public function testIssuingByHandTakesTheAmountOfThatPayment(): void
    {
        $advance = $this->advance([$this->line('1000.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $this->pay($advance, '400.00', '2026-11-02');
        $second = $this->pay($advance, '810.00', '2026-11-10');
        $this->em->getConnection()->executeStatement('UPDATE invoice SET tax_on_receipt = NULL WHERE id = ?', [$advance->id()]);
        $this->em->clear();

        $document = $this->taxDocuments()->byHand($advance->id(), $second->id());

        self::assertSame(81000, $this->reloaded($document)->payable()->minor());
        self::assertSame($second->id(), $this->reloaded($document)->paymentId());
    }

    public function testTheChainShowsAPaymentWithoutATaxDocumentAndEveryAdvanceDeducted(): void
    {
        $unknown = $this->advance([$this->line('1000.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $this->pay($unknown, '1210.00');
        self::assertNull(self::getContainer()->get(DocumentChain::class)->of($this->reloaded($unknown))[0]['payments'][0]['taxDocument']);

        $first = $this->advance([$this->line('1000.00')]);
        $second = $this->advance([$this->line('1000.00')]);
        $this->pay($first, '1210.00');
        $this->pay($second, '1210.00');
        $deductible = array_values(array_filter(self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech), static fn ($one): bool => str_starts_with($one->number, 'D8')));
        self::assertCount(2, $deductible);
        $final = $this->issue->issue($this->input([$this->line('5000.00'), ...$deductible[0]->lines, ...$deductible[1]->lines]));

        $chains = self::getContainer()->get(DocumentChain::class)->of($this->reloaded($final));
        self::assertSame([$first->id(), $second->id()], array_map(static fn (array $chain): string => $chain['advance']['id'], $chains));
        self::assertSame([[$final->id()], [$final->id()]], array_map(static fn (array $chain): array => array_column($chain['finals'], 'id'), $chains));
    }

    public function testAnAdvanceWithVatIsDeductedThroughItsTaxDocumentOnly(): void
    {
        $person = $this->person();
        $consumer = $this->advance([$this->line('1000.00')], ['customerId' => $person->id()]);
        $taxed = $this->advance([$this->line('1000.00')], ['customerId' => $person->id(), 'taxOnReceipt' => TaxOnReceiptChoice::Issue]);
        $this->pay($taxed, '1210.00', '2026-11-02');
        $this->pay($consumer, '1210.00', '2026-11-03');

        $offered = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $person->id(), null, DocumentLanguage::Czech);

        self::assertSame(['D820260001', 'Z920260001'], array_map(static fn ($deductible): string => $deductible->number, $offered));
    }

    public function testAPaymentWithoutVatBeforeOneWithVatStillLeavesTheSecondWaiting(): void
    {
        $person = $this->person();
        $this->pay($this->advance([$this->line('1000.00')], ['customerId' => $person->id()]), '1210.00', '2026-11-02');
        $waiting = $this->advance([$this->line('1000.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $this->pay($waiting, '1210.00', '2026-11-03');
        $this->em->getConnection()->executeStatement('UPDATE invoice SET tax_on_receipt = NULL WHERE id = ?', [$waiting->id()]);
        $this->em->clear();

        $missing = self::getContainer()->get(MissingTaxDocuments::class)->on(new \DateTimeImmutable('2026-11-18'));

        self::assertSame([$waiting->id()], array_map(static fn ($row): string => $row->advanceId, $missing));
        self::assertSame(0, $missing[0]->daysLeft);
        self::assertFalse($missing[0]->isLate());
    }

    public function testAVatNumberAbroadMakesTheSupplyCrossBorderWhateverTheAddress(): void
    {
        $buyer = new Party(PartyKind::Company, 'Example Branch GmbH', null, VatId::fromString('DE123456789'), '');
        $this->em->persist($buyer);
        $this->em->persist(new PartyAddress($buyer, AddressType::Billing, new Address('Branch Street 1', 'Example City', '100 00', 'CZ')));
        $this->em->flush();
        $advance = $this->advance([$this->line('1000.00', treatment: TaxTreatment::ReverseCharge, kind: null)], ['customerId' => $buyer->id()]);

        self::assertTrue(self::getContainer()->get(AdvanceTaxation::class)->of($this->reloaded($advance))->crossBorder);
    }

    public function testTheDefaultsOfTaxOnReceipt(): void
    {
        self::assertFalse(TaxOnReceipt::arises()->crossBorder);
        self::assertFalse(TaxOnReceipt::none(TaxOnReceipt::CONSUMER)->crossBorder);
        self::assertSame('2026-11-20', TaxOnReceipt::none(TaxOnReceipt::CONSUMER)->deadline(new \DateTimeImmutable('2026-11-05'))->format('Y-m-d'));
    }

    public function testEachDocumentKindHasItsMailTemplate(): void
    {
        self::assertSame([MailTemplateKind::Invoice, MailTemplateKind::Advance, MailTemplateKind::PaymentTaxDocument, MailTemplateKind::Invoice], array_map(MailTemplateKind::forDocument(...), DocumentKind::cases()));
    }

    public function testOnlyAnInvoiceThatDeductsAndIsNotTrashedIsSettledByAdvances(): void
    {
        $plain = $this->issue->issue($this->input([$this->line('0.00')]));
        self::assertSame(InvoiceStatus::Issued, InvoiceStatus::ofInvoice($this->reloaded($plain), new \DateTimeImmutable('2026-11-01'), $this->czk('0.00')));

        $this->pay($this->advance([$this->line('1000.00')]), '1210.00');
        $deduction = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech)[0]->lines;
        $final = $this->issue->issue($this->input([$this->line('1000.00'), ...$deduction]));
        $this->trash->trash($final->id(), 'Wrong customer');

        self::assertSame(InvoiceStatus::Trashed, InvoiceStatus::ofInvoice($this->reloaded($final), new \DateTimeImmutable('2026-11-01'), $this->czk('0.00')));
    }

    public function testAnAdvanceEditedWithDeductionsIsRefused(): void
    {
        $this->pay($this->advance([$this->line('1000.00')]), '1210.00');
        $deduction = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech)[0]->lines;
        $other = $this->advance([$this->line('3000.00')]);

        $this->assertRefusedFor('invoice.problem.deduction_invalid', fn () => $this->edited($other, $this->input([$this->line('3000.00'), ...$deduction], ['seriesId' => null])));
    }

    public function testTheRateDateIsTheTaxPointOfATaxDocument(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['issueDate' => new \DateTimeImmutable('2026-11-05'), 'taxPointDate' => new \DateTimeImmutable('2026-10-31')]));

        self::assertSame('2026-10-31', $this->reloaded($invoice)->rateDate()->format('Y-m-d'));
    }

    public function testOnlyATaxDocumentTaxesAPaymentAndOnlyOne(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $document = $this->onlyTaxDocumentOf($advance);

        foreach ([$this->reloaded($advance), $document] as $target) {
            try {
                $target->taxesPayment($advance->id(), $payment->id());
                self::fail('Expected a refusal.');
            } catch (\DomainException $refused) {
                self::assertSame('Only a tax document on payment taxes a payment, and only one.', $refused->getMessage());
            }
        }
    }

    public function testCancellingAPaymentLocksItsTaxDocumentsToo(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $document = $this->onlyTaxDocumentOf($advance);
        $log = new LockLog(self::getContainer()->get(Candidates::class));
        $container = self::getContainer();
        $wired = $container->get(CancelPayment::class);
        $documents = new \ReflectionProperty(CancelPayment::class, 'documents')->getValue($wired);
        $texts = new \ReflectionProperty(CancelPayment::class, 'texts')->getValue($wired);
        $corrections = new \ReflectionProperty(CancelPayment::class, 'corrections')->getValue($wired);
        self::assertInstanceOf(AdvanceDocuments::class, $documents);
        self::assertInstanceOf(PaymentTaxDocumentTexts::class, $texts);
        self::assertInstanceOf(CorrectPaymentTaxDocument::class, $corrections);
        $cancel = new CancelPayment(
            $this->payments,
            new LoggingInvoiceStore($this->store, $log),
            $container->get(TransactionalWork::class),
            $container->get(ConflictRetry::class),
            $container->get(AuditActor::class),
            Clock::get(),
            $container->get(PaymentSourceRelease::class),
            $documents,
            $texts,
            $corrections,
        );

        $cancel->cancel($payment->id());

        self::assertSame(['lock:'.$advance->id(), 'lock:'.$document->id()], $log->entries);
    }

    private function czech(): CzechInvoicing
    {
        return self::getContainer()->get(CzechInvoicing::class);
    }

    private function taxDocuments(): IssuePaymentTaxDocument
    {
        $service = new \ReflectionProperty(RecordPayment::class, 'taxDocuments')->getValue($this->record);
        self::assertInstanceOf(IssuePaymentTaxDocument::class, $service);

        return $service;
    }

    private function german(): Party
    {
        $party = new Party(PartyKind::Company, 'Example Buyer GmbH', null, VatId::fromString('DE123456789'), '');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE')));
        $this->em->flush();

        return $party;
    }

    private function person(): Party
    {
        $person = new Party(PartyKind::Person, 'Jana Example', null, null, '');
        $this->em->persist($person);
        $this->em->persist(new PartyAddress($person, AddressType::Billing, new Address('Home Street 1', 'Home City', '100 00', 'CZ')));
        $this->em->flush();

        return $person;
    }
}
