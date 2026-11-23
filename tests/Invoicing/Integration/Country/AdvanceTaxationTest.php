<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\AdvanceTaxation;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\TaxOnReceipt;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\TaxOnReceiptChoice;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;

/**
 * Whether a payment of an advance creates VAT and so needs a tax document (§20a,
 * §28(1)(d), §28(9)(d), §92a of the VAT Act), and the deadline when it does.
 */
final class AdvanceTaxationTest extends InvoiceTestCase
{
    public function testAStandardAdvanceOfAPayerArisesOnReceipt(): void
    {
        $taxation = $this->taxationOf($this->advance());

        self::assertTrue($taxation->arises);
        self::assertNull($taxation->reason);
        self::assertFalse($taxation->crossBorder);
    }

    public function testANonPayerIssuesNoneWhateverWasChosen(): void
    {
        $this->reconfigureFirm(VatStatus::NotRegistered, null);

        $taxation = $this->taxationOf($this->advance([$this->line(treatment: TaxTreatment::NotApplicable, kind: null)], ['taxOnReceipt' => TaxOnReceiptChoice::Issue]));

        self::assertFalse($taxation->arises);
        self::assertSame(TaxOnReceipt::NON_PAYER, $taxation->reason);
    }

    public function testAPrivatePersonIsOwedNone(): void
    {
        $person = $this->party(PartyKind::Person, null, 'CZ');

        $taxation = $this->taxationOf($this->advance(overrides: ['customerId' => $person->id()]));

        self::assertFalse($taxation->arises);
        self::assertSame(TaxOnReceipt::CONSUMER, $taxation->reason);
    }

    public function testAPersonCanAskForTheDocumentsAnyway(): void
    {
        $person = $this->party(PartyKind::Person, null, 'CZ');

        self::assertTrue($this->taxationOf($this->advance(overrides: ['customerId' => $person->id(), 'taxOnReceipt' => TaxOnReceiptChoice::Issue]))->arises);
    }

    public function testASupplyNotKnownYetCreatesNone(): void
    {
        $taxation = $this->taxationOf($this->advance(overrides: ['taxOnReceipt' => TaxOnReceiptChoice::Skip]));

        self::assertFalse($taxation->arises);
        self::assertSame(TaxOnReceipt::UNKNOWN_SUPPLY, $taxation->reason);
    }

    public function testADomesticReverseChargeIsTaxedAtSupply(): void
    {
        $taxation = $this->taxationOf($this->advance([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4'), $this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]));

        self::assertFalse($taxation->arises);
        self::assertSame(TaxOnReceipt::DOMESTIC_REVERSE_CHARGE, $taxation->reason);
    }

    public function testAMixedAdvanceWithAStandardLineArises(): void
    {
        $taxation = $this->taxationOf($this->advance([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4'), $this->line()]));

        self::assertTrue($taxation->arises);
        self::assertFalse($taxation->crossBorder);
    }

    public function testAnEuServiceArisesAndIsDueAfterTheEndOfTheMonth(): void
    {
        $german = $this->party(PartyKind::Company, 'DE123456789', 'DE');

        $taxation = $this->taxationOf($this->advance([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null)], ['customerId' => $german->id()]));

        self::assertTrue($taxation->arises);
        self::assertTrue($taxation->crossBorder);
    }

    public function testAForeignCustomerWithoutAVatNumberCountsByTheAddress(): void
    {
        $foreign = $this->party(PartyKind::Company, null, 'DE');
        $advance = $this->advance(overrides: ['customerId' => $foreign->id()]);
        $this->rewriteTreatment($advance, TaxTreatment::ReverseCharge);

        self::assertTrue($this->taxationOf($advance)->crossBorder);
    }

    public function testOnlyAnAdvanceIsAsked(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Only an advance invoice is taxed on receipt.');

        $this->taxationOf($invoice);
    }

    public function testTheChoiceIsStoredOnAnAdvanceOnly(): void
    {
        $advance = $this->advance(overrides: ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $invoice = $this->issue->issue($this->input(overrides: ['taxOnReceipt' => TaxOnReceiptChoice::Skip]));

        self::assertSame(TaxOnReceiptChoice::Skip, $this->reloaded($advance)->taxOnReceipt());
        self::assertNull($this->reloaded($invoice)->taxOnReceipt());
        self::assertSame('skip', $this->em->getConnection()->fetchOne('SELECT tax_on_receipt FROM invoice WHERE id = ?', [$advance->id()]));
    }

    public function testAnEditCanDropTheChoice(): void
    {
        $advance = $this->advance(overrides: ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);

        $this->edited($advance, $this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::Advance, 'taxOnReceipt' => null]));

        self::assertNull($this->reloaded($advance)->taxOnReceipt());
    }

    private function taxationOf(Invoice $advance): TaxOnReceipt
    {
        return self::getContainer()->get(AdvanceTaxation::class)->of($this->reloaded($advance));
    }

    /**
     * @param list<LineInput>|null $lines
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    private function advance(?array $lines = null, array $overrides = []): Invoice
    {
        return $this->issue->issue($this->advanceInput($lines, $overrides));
    }

    /**
     * @param list<LineInput>|null $lines
     * @param array<string, mixed> $overrides
     */
    private function advanceInput(?array $lines = null, array $overrides = []): InvoiceInput
    {
        return $this->input($lines, [...['seriesId' => null, 'kind' => DocumentKind::Advance], ...$overrides]);
    }

    private function party(PartyKind $kind, ?string $vatId, string $country): Party
    {
        $registration = PartyKind::Person === $kind || null !== $vatId ? null : new RegistrationNumber($country, 'HRB 12345');
        $party = new Party($kind, PartyKind::Person === $kind ? 'Jana Example' : 'Example Buyer GmbH', $registration, null === $vatId ? null : VatId::fromString($vatId), '');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Buyer Street 3', 'Buyer City', '300 00', $country)));
        $this->em->flush();

        return $party;
    }

    /**
     * A reverse charge line a person could not enter without a VAT number, as an
     * import might store it.
     */
    private function rewriteTreatment(Invoice $advance, TaxTreatment $treatment): void
    {
        $this->em->getConnection()->executeStatement('UPDATE invoice_line SET treatment = ? WHERE invoice_id = ?', [$treatment->value, $advance->id()]);
    }
}
