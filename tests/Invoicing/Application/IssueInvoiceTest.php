<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\DocumentParties;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class IssueInvoiceTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    public function testARegisteredFirmWithoutAVatIdIsRefusedAtIssue(): void
    {
        $this->reconfigureFirm(VatStatus::Registered, null);

        $this->assertRefusedFor('invoice.problem.firm_vat_id_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['seriesId' => null])));
    }

    public function testAFirmNeedsARegisterEntry(): void
    {
        $this->reconfigureFirm(VatStatus::Registered, 'CZ27074358', '');

        $this->assertRefusedFor('invoice.problem.firm_register_entry_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['seriesId' => null])));
    }

    public function testAFirmInAnUnsupportedCountryIssuesNothing(): void
    {
        $this->reconfigureFirm(VatStatus::Registered, 'DE123456789', 'Register', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));

        $this->assertRefusedFor('invoice.problem.firm_country_unsupported', fn (): Invoice => $this->issue->issue($this->input(overrides: ['seriesId' => null])));
    }

    public function testAnArchivedContactIsRefused(): void
    {
        $this->customer->archive(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_archived', fn (): Invoice => $this->issue->issue($this->input()));
    }

    public function testAnUnknownCustomerIsRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.customer_unknown', fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => '01963c5e-0000-7000-8000-000000000000'])));
    }

    public function testAnArchivedSeriesIsRefused(): void
    {
        $this->series->archive();
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.series_archived', fn (): Invoice => $this->issue->issue($this->input()));
    }

    public function testABankTransferNeedsAnIbanOfTheFirm(): void
    {
        $this->assertRefusedFor('invoice.problem.bank_account_foreign', fn (): Invoice => $this->issue->issue($this->input(overrides: ['bankAccount' => 'CZ5508000000001234567899'])));
        $this->assertRefusedFor('invoice.problem.bank_account_foreign', fn (): Invoice => $this->issue->issue($this->input(overrides: ['bankAccount' => null])));
        $this->assertRefusedFor('invoice.problem.bank_account_foreign', fn (): Invoice => $this->issue->issue($this->input(overrides: ['bankAccount' => 'nonsense'])));
    }

    public function testTheCustomerAddressMustBeComplete(): void
    {
        $customer = $this->customer(address: new Address('', 'Customer City', '200 00', 'CZ'));
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_address_incomplete', fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()])));
    }

    public function testAnIdentifiedPersonCannotIssueYet(): void
    {
        $this->reconfigureFirm(VatStatus::IdentifiedPerson, 'CZ27074358');

        $this->assertRefusedFor('invoice.problem.firm_identified_person', fn (): Invoice => $this->issue->issue($this->input(overrides: ['seriesId' => null])));
    }

    public function testTaxPointAndDueDateMustBePresent(): void
    {
        $this->assertRefusedFor('invoice.problem.tax_point_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['taxPointDate' => null])));
        $this->assertRefusedFor('invoice.problem.due_date_missing', fn (): Invoice => $this->issue->issue($this->input(overrides: ['dueDate' => null])));
    }

    public function testValuesThatDoNotFitTheirColumnsAreRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.too_long', fn (): Invoice => $this->issue->issue($this->input(overrides: ['orderNumber' => str_repeat('1', 65)])));
        $this->assertRefusedFor('invoice.problem.too_long', fn (): Invoice => $this->issue->issue($this->input(overrides: ['constantSymbol' => str_repeat('1', 11)])));
    }

    public function testATypedNumberNeedsLettersOrDigits(): void
    {
        $this->assertRefusedFor('invoice.problem.number_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => '---'])));
        $this->assertRefusedFor('invoice.problem.number_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['typedNumber' => str_repeat('1', 81)])));
    }

    public function testAContactWithoutABillingAddressIsRefused(): void
    {
        $customer = new Party(PartyKind::Company, 'No Address', new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em->persist($customer);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.customer_address_incomplete', fn (): Invoice => $this->issue->issue($this->input(overrides: ['customerId' => $customer->id()])));
    }

    public function testAProblemOfTheFirmStaysWhenItsCountryIsUnsupported(): void
    {
        $this->reconfigureFirm(VatStatus::Registered, 'DE123456789', '', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));

        $keys = $this->refusalOf(fn (): Invoice => $this->issue->issue($this->input(overrides: ['seriesId' => null])));

        self::assertSame(['invoice.problem.firm_register_entry_missing', 'invoice.problem.firm_country_unsupported'], $keys);
    }

    public function testARefusalNamesItsProblems(): void
    {
        $refused = new IssueRefused([new IssueProblem('a', 'first'), new IssueProblem('b', 'second')]);

        self::assertSame('The document cannot be saved: first, second', $refused->getMessage());
    }

    public function testAnInputWithoutDatesGivesNoChange(): void
    {
        $copy = DocumentParties::ofCustomer($this->customer, $this->address(), null);

        foreach (['taxPointDate', 'dueDate'] as $date) {
            try {
                $this->input(overrides: [$date => null])->change($this->customer, $copy, DocumentKind::Invoice);
                self::fail('Expected the guard.');
            } catch (\LogicException $guard) {
                self::assertStringContainsString('IssueRules refuses', $guard->getMessage());
            }
        }
    }

    public function testAConstantSymbolHasDigitsOnly(): void
    {
        $this->assertRefusedFor('invoice.problem.constant_symbol_invalid', fn (): Invoice => $this->issue->issue($this->input(overrides: ['constantSymbol' => '03a8'])));
    }

    private function address(): Address
    {
        return new Address('Street 1', 'City', '100 00', 'CZ');
    }
}
