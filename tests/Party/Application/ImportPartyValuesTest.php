<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Country\CzechRepublic\Party\CzechRegistrationNumberValidator;
use App\Party\Application\ImportedParty;
use App\Party\Application\ImportPartyValues;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\DomesticBankAccount;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class ImportPartyValuesTest extends TestCase
{
    public function testTextsAreLimitedByCharactersNotBytes(): void
    {
        self::assertSame([], $this->read(['display_name' => str_repeat('ř', 255)])->errors);

        $row = $this->read(['display_name' => str_repeat('a', 256)]);

        self::assertSame(['display_name' => ['import.error.too_long', ['value' => str_repeat('a', 256), 'max' => 255]]], $row->errors);
    }

    public function testTheFirstAddressIsGeneralAndTheOthersAreCopiesWithoutRepeats(): void
    {
        $row = $this->read([
            'display_name' => 'Acme',
            'email' => ['a@example.com', 'b@example.com', 'c@example.com'],
            'copy_email' => ['b@example.com', 'd@example.com', 'a@example.com'],
        ]);

        self::assertSame('a@example.com', $row->generalEmail);
        self::assertSame(['b@example.com', 'c@example.com', 'd@example.com'], $row->copyEmails);
    }

    public function testEmailsAreCheckedByLengthAndShapeAndNonTextIsIgnored(): void
    {
        $longest = str_repeat('a', 249).'@x.cz';
        self::assertSame(254, \strlen($longest));
        self::assertSame($longest, $this->read(['display_name' => 'Acme', 'email' => $longest])->generalEmail);

        $row = $this->read(['display_name' => 'Acme', 'email' => 'a'.$longest]);
        self::assertSame(['email' => ['import.error.invalid_email', ['value' => 'a'.$longest]]], $row->errors);

        $row = $this->read(['display_name' => 'Acme', 'email' => ['a@example.com', 5, null]]);
        self::assertSame([], $row->errors);
        self::assertSame('a@example.com', $row->generalEmail);
    }

    public function testOneInvalidAddressDropsTheWholeCell(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'email' => ['not an address', 'ok@example.com']]);

        self::assertSame(['email' => ['import.error.invalid_email', ['value' => 'not an address']]], $row->errors);
        self::assertNull($row->generalEmail);
        self::assertSame([], $row->copyEmails);
    }

    public function testAValueThatIsNotTextCountsAsEmpty(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'vat_id' => 123, 'registration_number' => 12345687, 'phone' => '']);

        self::assertNull($row->vatId);
        self::assertNull($row->registrationNumber);
        self::assertSame([], $row->errors);
    }

    public function testInvalidIdentifiersNameTheValueThatWasRead(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'vat_id' => '1', 'registration_number' => '12345680']);

        self::assertSame(['import.error.invalid_vat_id', ['value' => '1']], $row->errors['vat_id']);
        self::assertSame(['import.error.invalid_registration_number', ['value' => '12345680']], $row->errors['registration_number']);
    }

    public function testAPersonWithAVatIdIsRecognisedByTheirNameWhateverTheCase(): void
    {
        $row = $this->read(['display_name' => 'řehoř novák', 'contact_name' => 'ŘEHOŘ NOVÁK', 'vat_id' => 'CZ12345687']);

        self::assertSame(PartyKind::Person, $row->kind);
        self::assertNull($row->contact);
    }

    public function testACompanyWithoutANameButWithAVatIdIsACompany(): void
    {
        $row = $this->read(['vat_id' => 'CZ12345687']);

        self::assertSame(PartyKind::Company, $row->kind);
        self::assertSame(['import.error.required', []], $row->errors['display_name']);
    }

    public function testAContactIsKeptUpToTheLengthOfAName(): void
    {
        $name = str_repeat('a', 255);
        $row = $this->read(['display_name' => 'Acme', 'registration_number' => '12345687', 'contact_name' => $name, 'contact_email' => 'c@example.com']);
        self::assertSame($name, $row->contact?->name);
        self::assertSame('c@example.com', $row->contact->email);

        $row = $this->read(['display_name' => 'Acme', 'registration_number' => '12345687', 'contact_name' => $name.'a']);
        self::assertNull($row->contact);
        self::assertSame(['import.error.too_long', ['value' => $name.'a', 'max' => 255]], $row->errors['contact_last_name']);

        $row = $this->read(['display_name' => 'Acme', 'registration_number' => '12345687', 'contact_name' => str_repeat('ř', 255)]);
        self::assertNotNull($row->contact);
    }

    public function testAnAddressNeedsStreetCityAndPostalCode(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'billing_street' => 'Main 1']);

        self::assertTrue($row->billingGiven);
        self::assertSame(['import.error.required', []], $row->errors['billing_city']);
        self::assertSame(['import.error.required', []], $row->errors['billing_postal_code']);
        self::assertArrayNotHasKey('billing_street', $row->errors);
    }

    public function testRolesAreTrimmedCaseInsensitiveAndWithoutRepeats(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'roles' => ' Customer ; supplier,,CUSTOMER ']);

        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $row->roles);

        $row = $this->read(['display_name' => 'Acme', 'roles' => 'customer, bogus ']);
        self::assertSame(['import.error.unknown_value', ['value' => 'bogus']], $row->errors['roles']);
        self::assertSame([], $row->roles);
    }

    public function testTheDuePeriodIsBetweenZeroAndAYear(): void
    {
        self::assertSame([], $this->read(['display_name' => 'Acme', 'due_days' => 0])->errors);
        self::assertSame([], $this->read(['display_name' => 'Acme', 'due_days' => 365])->errors);
        self::assertSame(['import.error.out_of_range', ['value' => -1, 'min' => 0, 'max' => 365]], $this->read(['display_name' => 'Acme', 'due_days' => -1])->errors['due_days']);
        self::assertSame(['import.error.out_of_range', ['value' => 366, 'min' => 0, 'max' => 365]], $this->read(['display_name' => 'Acme', 'due_days' => 366])->errors['due_days']);
    }

    public function testBankDetailsNameTheValueThatWasRead(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'bank_account_bic' => 'bad']);
        self::assertSame(['import.error.invalid_bic', ['value' => 'BAD']], $row->errors['bank_account_bic']);
        self::assertNull($row->bic);

        $row = $this->read(['display_name' => 'Acme', 'bank_account_iban' => 'CZ00', 'bank_account_bic' => 'kombczpp']);
        self::assertSame(['import.error.invalid_iban', ['value' => 'CZ00']], $row->errors['bank_account_iban']);
        self::assertNull($row->iban);
        self::assertNull($row->bic);
    }

    public function testAnInvalidIbanIsNotReplacedByTheDomesticAccountOfTheRow(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'bank_account_iban' => 'CZ00', 'bank_account_number' => '2000145399', 'bank_code' => '0800']);

        self::assertNull($row->iban);
    }

    public function testADomesticAccountMakesAnIbanAndHalfAnAccountIsAnError(): void
    {
        $row = $this->read(['display_name' => 'Acme', 'bank_account_number' => '19-2000145399', 'bank_code' => '800', 'bank_account_bic' => 'kombczpp']);
        self::assertNotNull($row->iban);
        self::assertSame('KOMBCZPP', $row->bic);
        self::assertSame([], $row->errors);

        $row = $this->read(['display_name' => 'Acme', 'bank_account_number' => '123']);
        self::assertSame(['import.error.invalid_bank_account', ['value' => '123/']], $row->errors['bank_code']);

        $row = $this->read(['display_name' => 'Acme', 'bank_code' => '0800']);
        self::assertSame(['import.error.invalid_bank_account', ['value' => '/0800']], $row->errors['bank_account_number']);

        $row = $this->read(['display_name' => 'Acme', 'bank_account_number' => 'abc', 'bank_code' => '0800']);
        self::assertSame(['import.error.invalid_bank_account', ['value' => 'abc/0800']], $row->errors['bank_account_number']);
    }

    public function testTheFirstCountryThatRefusesTheAccountDecidesTheOutcome(): void
    {
        $refusing = new class implements DomesticBankAccount {
            public function supports(string $countryCode): bool
            {
                return true;
            }

            public function toIban(string $number, string $bankCode): never
            {
                throw new InvalidIban('refused');
            }
        };
        $values = new ImportPartyValues(new RegistrationNumberFactory([new CzechRegistrationNumberValidator()]), [$refusing, new CzechBankAccount()]);

        $row = $values->read(['display_name' => 'Acme', 'bank_account_number' => '2000145399', 'bank_code' => '0800']);

        self::assertNull($row->iban);
        self::assertArrayHasKey('bank_account_number', $row->errors);
    }

    public function testInvoicingDefaultsTakeWhatTheRowSaysAndKeepTheBase(): void
    {
        $base = new InvoicingDefaults(30, Currency::EUR, InvoicingDefaults::standard()->language, PaymentMethod::Cash, true);
        $values = $this->values();

        $defaults = $values->invoicingDefaults($this->read(['display_name' => 'Acme', 'due_days' => 7, 'currency' => 'USD']), $base, false);

        self::assertSame(7, $defaults->dueDays);
        self::assertSame(Currency::USD, $defaults->currency);
        self::assertSame(PaymentMethod::Cash, $defaults->paymentMethod);
        self::assertTrue($defaults->reverseCharge);

        $cleared = $values->invoicingDefaults($this->read(['display_name' => 'Acme', 'due_days' => null, 'currency' => null, 'payment_method' => null]), $base, true);
        self::assertTrue($cleared->equals(new InvoicingDefaults(14, Currency::CZK, $base->language, PaymentMethod::BankTransfer, true)));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function read(array $values): ImportedParty
    {
        return $this->values()->read($values);
    }

    private function values(): ImportPartyValues
    {
        return new ImportPartyValues(new RegistrationNumberFactory([new CzechRegistrationNumberValidator()]), [new CzechBankAccount()]);
    }
}
