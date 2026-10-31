<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\DomesticBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Shared\Domain\Currency;

/**
 * Turns the normalised values of an imported row (by field key) into the
 * domain values of a party, its form input and the children it brings along.
 * Every rule a write would break is found here first, so a bad row is an
 * error row and never a failed write.
 */
final readonly class ImportPartyValues
{
    /** The country a row means when it names none: the tenants are Czech for now. */
    public const string DEFAULT_COUNTRY = 'CZ';

    private const int MAX_DUE_DAYS = 365;
    private const int MAX_EMAIL = 254;

    /** The column limits of the fields the importer writes as text. */
    private const array LIMITS = [
        'display_name' => 255,
        'phone' => 64,
        'mobile' => 64,
        'website' => 255,
        'billing_street' => 255,
        'billing_city' => 128,
        'billing_postal_code' => 32,
        'delivery_street' => 255,
        'delivery_city' => 128,
        'delivery_postal_code' => 32,
        'contact_phone' => 64,
        'contact_position' => 100,
    ];

    private const array ADDRESS_PARTS = ['street', 'city', 'postal_code'];

    /** The rule PartyEmail enforces, which is stricter than the e-mail reader of the import. */
    private const string EMAIL_PATTERN = '/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/';

    private const string BIC_PATTERN = '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\z/';

    /**
     * @param iterable<DomesticBankAccount> $domesticAccounts
     */
    public function __construct(
        private RegistrationNumberFactory $registrationNumbers,
        private iterable $domesticAccounts,
    ) {
    }

    /**
     * @param array<string, mixed> $values normalised by field key; null for an empty cell
     */
    public function read(array $values): ImportedParty
    {
        $errors = [];
        foreach (self::LIMITS as $field => $max) {
            $text = $this->string($values, $field);
            if (null !== $text && mb_strlen($text) > $max) {
                $errors[$field] = ['import.error.too_long', ['value' => $text, 'max' => $max]];
            }
        }

        $contactName = $this->contactName($values);
        $displayName = $this->string($values, 'display_name') ?? $contactName;
        if ('' === $displayName) {
            $errors['display_name'] = ['import.error.required', []];
        }

        $registrationCountry = $this->string($values, 'registration_country') ?? self::DEFAULT_COUNTRY;
        $registrationNumber = $this->registrationNumber($values, $registrationCountry, $errors);
        $vatId = $this->vatId($values, $errors);
        $kind = $this->kind($values, $displayName, $contactName);
        if (PartyKind::Company === $kind && !$registrationNumber instanceof RegistrationNumber && !$vatId instanceof VatId && !isset($errors['registration_number']) && !isset($errors['vat_id'])) {
            $errors['registration_number'] = ['import.error.company_identifier', []];
        }

        // The first address of "email" is the general one; the others and the copy column are copies.
        $emails = $this->emails($values, 'email', $errors);
        $generalEmail = $emails[0] ?? null;
        $copies = array_values(array_diff(array_unique([...array_slice($emails, 1), ...$this->emails($values, 'copy_email', $errors)]), [$generalEmail]));
        $invoiceEmail = $this->emails($values, 'invoice_email', $errors)[0] ?? null;

        $billingGiven = $this->addressGiven($values, 'billing');
        $billingCountry = $this->string($values, 'billing_country') ?? $registrationCountry;
        $billing = $this->address($values, 'billing', $billingCountry, $errors) ?? new Address('', '', '', $billingCountry);
        $delivery = $this->address($values, 'delivery', $this->string($values, 'delivery_country') ?? $billingCountry, $errors);

        [$iban, $bic] = $this->bankAccount($values, $errors);
        $contact = $this->contact($values, $contactName, $errors);
        $roles = $this->roles($values, $errors);
        $this->checkInvoicing($values, $errors);

        return new ImportedParty(
            $values,
            $errors,
            $kind,
            $displayName,
            $registrationNumber,
            $vatId,
            $generalEmail,
            $copies,
            $invoiceEmail,
            $billing,
            $billingGiven,
            $delivery,
            $iban,
            $bic,
            $contact,
            $roles,
        );
    }

    /**
     * The form input of a new party. A row without roles makes a customer.
     */
    public function input(ImportedParty $row): PartyInput
    {
        return new PartyInput(
            $row->kind,
            $row->displayName,
            $row->registrationNumber,
            $row->vatId,
            $row->generalEmail ?? '',
            $row->text('phone'),
            [] === $row->roles ? [PartyRole::Customer] : $row->roles,
            $row->billingAddress,
            $row->text('private_note'),
            $row->text('mobile'),
            $row->text('website'),
            $this->invoicingDefaults($row, InvoicingDefaults::standard(), false),
        );
    }

    /**
     * The form input that updates a stored party with the row: a value of the
     * row overwrites, a field the row has no column for stays, and an empty
     * cell stays too unless $clear, which empties the optional fields. The
     * kind never changes, the name is required and a company keeps an
     * identifier; the general e-mail is replaced but never removed.
     */
    public function merge(Party $party, ?Address $billing, string $generalEmail, ImportedParty $row, bool $clear): PartyInput
    {
        $registrationNumber = $this->pick($row, 'registration_number', $party->registrationNumber(), $row->registrationNumber, $clear);
        $vatId = $this->pick($row, 'vat_id', $party->vatId(), $row->vatId, $clear);
        if ($vatId instanceof VatId && $vatId->toString() === $party->vatId()?->toString()) {
            // The same id as a new object would still count as a change.
            $vatId = $party->vatId();
        }
        if (PartyKind::Company === $party->kind() && !$registrationNumber instanceof RegistrationNumber && !$vatId instanceof VatId) {
            $registrationNumber = $party->registrationNumber();
            $vatId = $party->vatId();
        }
        $current = $billing ?? new Address('', '', '', self::DEFAULT_COUNTRY);
        $address = new Address(
            $this->pickText($row, 'billing_street', $current->street, $clear),
            $this->pickText($row, 'billing_city', $current->city, $clear),
            $this->pickText($row, 'billing_postal_code', $current->postalCode, $clear),
            $this->pickText($row, 'billing_country', $current->countryCode, $clear, $row->billingAddress->countryCode),
        );

        return new PartyInput(
            $party->kind(),
            $row->given('display_name') ? $row->displayName : $party->displayName(),
            $registrationNumber,
            $vatId,
            $row->generalEmail ?? $generalEmail,
            $this->pickText($row, 'phone', $party->phone(), $clear),
            $this->pick($row, 'roles', $party->roles(), $row->roles, $clear) ?? [],
            $address,
            $this->pickText($row, 'private_note', $party->privateNote(), $clear),
            $this->pickText($row, 'mobile', $party->mobile(), $clear),
            $this->pickText($row, 'website', $party->website(), $clear),
            $this->invoicingDefaults($row, $party->invoicingDefaults(), $clear),
        );
    }

    /**
     * The document defaults with the due period, currency and payment method of the row.
     */
    public function invoicingDefaults(ImportedParty $row, InvoicingDefaults $base, bool $clear): InvoicingDefaults
    {
        $standard = InvoicingDefaults::standard();
        $dueDays = $row->values['due_days'] ?? null;
        $currency = Currency::tryFrom($row->text('currency'));
        $method = PaymentMethod::tryFrom($row->text('payment_method'));

        return new InvoicingDefaults(
            \is_int($dueDays) ? $dueDays : ($clear && $row->blank('due_days') ? $standard->dueDays : $base->dueDays),
            $currency ?? ($clear && $row->blank('currency') ? $standard->currency : $base->currency),
            $base->language,
            $method ?? ($clear && $row->blank('payment_method') ? $standard->paymentMethod : $base->paymentMethod),
            $base->reverseCharge,
        );
    }

    /**
     * @template T
     *
     * @param T $current
     * @param T $new
     *
     * @return T|null
     */
    private function pick(ImportedParty $row, string $field, mixed $current, mixed $new, bool $clear): mixed
    {
        if ($row->given($field)) {
            return $new;
        }

        return $clear && $row->blank($field) ? null : $current;
    }

    private function pickText(ImportedParty $row, string $field, string $current, bool $clear, string $cleared = ''): string
    {
        if ($row->given($field)) {
            return $row->text($field);
        }

        return $clear && $row->blank($field) ? $cleared : $current;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function string(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * The person's name: as one field, or composed of title, first and last name.
     *
     * @param array<string, mixed> $values
     */
    private function contactName(array $values): string
    {
        $name = $this->string($values, 'contact_name');
        if (null !== $name) {
            return $name;
        }
        $parts = array_filter([$this->string($values, 'contact_title'), $this->string($values, 'contact_first_name'), $this->string($values, 'contact_last_name')], static fn (?string $part): bool => null !== $part);

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     */
    private function registrationNumber(array $values, string $country, array &$errors): ?RegistrationNumber
    {
        $raw = $this->string($values, 'registration_number');
        if (null === $raw) {
            return null;
        }

        try {
            // The factory pads a Czech number to eight digits before it checks the check digit.
            return $this->registrationNumbers->create($country, $raw);
        } catch (InvalidRegistrationNumber) {
            $errors['registration_number'] = ['import.error.invalid_registration_number', ['value' => $raw]];

            return null;
        }
    }

    /**
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     */
    private function vatId(array $values, array &$errors): ?VatId
    {
        $raw = $this->string($values, 'vat_id');
        if (null === $raw) {
            return null;
        }

        try {
            return VatId::fromString($raw);
        } catch (InvalidVatId) {
            $errors['vat_id'] = ['import.error.invalid_vat_id', ['value' => $raw]];

            return null;
        }
    }

    /**
     * The kind the row names, or else: a company when it has a registration
     * number; a person when the contact name is the name of the row (that is
     * how programs export a person); a company when it has a VAT id; a person otherwise.
     *
     * @param array<string, mixed> $values
     */
    private function kind(array $values, string $displayName, string $contactName): PartyKind
    {
        $kind = PartyKind::tryFrom($this->string($values, 'kind') ?? '');
        if ($kind instanceof PartyKind) {
            return $kind;
        }
        if (null !== $this->string($values, 'registration_number')) {
            return PartyKind::Company;
        }
        if ('' !== $contactName && mb_strtolower($contactName) === mb_strtolower($displayName)) {
            return PartyKind::Person;
        }

        return null !== $this->string($values, 'vat_id') ? PartyKind::Company : PartyKind::Person;
    }

    /**
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     *
     * @return list<string>
     */
    private function emails(array $values, string $field, array &$errors): array
    {
        $value = $values[$field] ?? null;
        $addresses = \is_array($value) ? array_values(array_filter($value, \is_string(...))) : (\is_string($value) ? [$value] : []);
        foreach ($addresses as $address) {
            if (\strlen($address) > self::MAX_EMAIL || 1 !== preg_match(self::EMAIL_PATTERN, $address)) {
                $errors[$field] = ['import.error.invalid_email', ['value' => $address]];

                return [];
            }
        }

        return $addresses;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function addressGiven(array $values, string $prefix): bool
    {
        return array_any(self::ADDRESS_PARTS, fn (string $part): bool => null !== $this->string($values, $prefix.'_'.$part));
    }

    /**
     * An address needs street, city and postal code; a row without any of them has none.
     *
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     */
    private function address(array $values, string $prefix, string $country, array &$errors): ?Address
    {
        if (!$this->addressGiven($values, $prefix)) {
            return null;
        }
        $parts = [];
        foreach (self::ADDRESS_PARTS as $part) {
            $parts[$part] = $this->string($values, $prefix.'_'.$part);
            if (null === $parts[$part]) {
                $errors[$prefix.'_'.$part] ??= ['import.error.required', []];
            }
        }

        return new Address($parts['street'] ?? '', $parts['city'] ?? '', $parts['postal_code'] ?? '', $country);
    }

    /**
     * The IBAN of the row, or the one its domestic account number and bank code make.
     *
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     *
     * @return array{Iban|null, string|null}
     */
    private function bankAccount(array $values, array &$errors): array
    {
        $bic = $this->string($values, 'bank_account_bic');
        if (null !== $bic) {
            $bic = strtoupper($bic);
            if (1 !== preg_match(self::BIC_PATTERN, $bic)) {
                $errors['bank_account_bic'] = ['import.error.invalid_bic', ['value' => $bic]];
                $bic = null;
            }
        }

        $raw = $this->string($values, 'bank_account_iban');
        if (null !== $raw) {
            try {
                return [Iban::fromString($raw), $bic];
            } catch (InvalidIban) {
                $errors['bank_account_iban'] = ['import.error.invalid_iban', ['value' => $raw]];

                return [null, null];
            }
        }

        $number = $this->string($values, 'bank_account_number');
        $code = $this->string($values, 'bank_code');
        if (null === $number && null === $code) {
            return [null, null];
        }
        $text = ($number ?? '').'/'.($code ?? '');
        if (null === $number || null === $code) {
            // The half that is there is not enough; the error belongs to the half that is missing.
            $errors[null === $number ? 'bank_account_number' : 'bank_code'] = ['import.error.invalid_bank_account', ['value' => $text]];

            return [null, null];
        }
        foreach ($this->domesticAccounts as $accounts) {
            if ($accounts->supports(self::DEFAULT_COUNTRY)) {
                try {
                    return [$accounts->toIban($number, $code), $bic];
                } catch (InvalidIban) {
                    break;
                }
            }
        }
        $errors['bank_account_number'] = ['import.error.invalid_bank_account', ['value' => $text]];

        return [null, null];
    }

    /**
     * The contact person the row names; a row whose contact is its own name
     * (a person, as programs export one) has none. Only a company or a sole
     * trader gets one: the kind of a stored party is the one that counts.
     *
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     */
    private function contact(array $values, string $contactName, array &$errors): ?ImportedContact
    {
        $email = $this->emails($values, 'contact_email', $errors)[0] ?? '';
        $name = $this->string($values, 'display_name') ?? '';
        if ('' === $contactName || mb_strtolower($contactName) === mb_strtolower($name)) {
            return null;
        }
        if (mb_strlen($contactName) > self::LIMITS['display_name']) {
            $errors['contact_last_name'] = ['import.error.too_long', ['value' => $contactName, 'max' => self::LIMITS['display_name']]];

            return null;
        }

        return new ImportedContact($contactName, $email, $this->string($values, 'contact_phone') ?? '', $this->string($values, 'contact_position') ?? '');
    }

    /**
     * Roles separated by commas or semicolons, by their key ("customer, supplier").
     *
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     *
     * @return list<PartyRole>
     */
    private function roles(array $values, array &$errors): array
    {
        $text = $this->string($values, 'roles');
        if (null === $text) {
            return [];
        }
        $roles = [];
        $words = preg_split('/[,;]/', $text);
        foreach (false === $words ? [] : $words as $word) {
            $word = trim($word);
            if ('' === $word) {
                continue;
            }
            $role = PartyRole::tryFrom(mb_strtolower($word));
            if (!$role instanceof PartyRole) {
                $errors['roles'] = ['import.error.unknown_value', ['value' => $word]];

                return [];
            }
            $roles[$role->value] = $role;
        }

        return array_values($roles);
    }

    /**
     * @param array<string, mixed>                                    $values
     * @param array<string, array{string, array<string, int|string>}> $errors
     */
    private function checkInvoicing(array $values, array &$errors): void
    {
        $dueDays = $values['due_days'] ?? null;
        if (\is_int($dueDays) && ($dueDays < 0 || $dueDays > self::MAX_DUE_DAYS)) {
            $errors['due_days'] = ['import.error.out_of_range', ['value' => $dueDays, 'min' => 0, 'max' => self::MAX_DUE_DAYS]];
        }
    }
}
