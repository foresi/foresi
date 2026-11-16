<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Shared\Domain\Countries;

/**
 * The rules of a contact, the same for the screens, the API and MCP (ADR 0032):
 * the lengths of the fields, the required ones, the countries, a valid e-mail,
 * registration number and VAT id, that a company has one of the two, and the
 * kinds a contact person link needs. Every field is checked, so a form can show
 * each problem at its field.
 */
final readonly class PartyRules
{
    public const int MAX_NAME = 255;
    public const int MAX_PHONE = 64;
    public const int MAX_WEBSITE = 255;
    public const int MAX_STREET = 255;
    public const int MAX_CITY = 128;
    public const int MAX_POSTAL_CODE = 32;
    public const int MAX_EMAIL = 254;
    public const int MAX_POSITION = 100;
    public const int MAX_REGISTRATION_NUMBER = 64;
    /** The longest VAT id a client may send; VatId checks its shape. */
    public const int MAX_VAT_ID = 32;
    /** An ISO 3166-1 alpha-2 code, which Countries checks. */
    public const int COUNTRY_CODE_LENGTH = 2;

    public function __construct(private RegistrationNumberFactory $registrationNumbers)
    {
    }

    public function parse(PartyDraft $draft): ParsedParty
    {
        $refusals = [];
        $kind = PartyKind::tryFrom($draft->kind);
        if (!$kind instanceof PartyKind) {
            $refusals['kind'] = new PartyRefused(PartyRefused::KIND, 'kind');
        }
        $this->required($refusals, 'displayName', $draft->displayName, self::MAX_NAME);
        $this->required($refusals, 'street', $draft->street, self::MAX_STREET);
        $this->required($refusals, 'city', $draft->city, self::MAX_CITY);
        $this->required($refusals, 'postalCode', $draft->postalCode, self::MAX_POSTAL_CODE);
        $this->longest($refusals, 'phone', $draft->phone, self::MAX_PHONE);
        $this->longest($refusals, 'mobile', $draft->mobile, self::MAX_PHONE);
        $this->longest($refusals, 'website', $draft->website, self::MAX_WEBSITE);
        if (!Countries::supports($draft->countryCode)) {
            $refusals['countryCode'] = new PartyRefused('' === $draft->countryCode ? PartyRefused::REQUIRED : PartyRefused::COUNTRY, 'countryCode');
        }
        if ('' !== $draft->email && (\strlen($draft->email) > self::MAX_EMAIL || !PartyEmail::isValid($draft->email))) {
            $refusals['email'] = new PartyRefused(PartyRefused::EMAIL, 'email');
        }
        $roles = [];
        foreach ($draft->roles as $value) {
            $role = PartyRole::tryFrom($value);
            if (!$role instanceof PartyRole) {
                $refusals['roles'] = new PartyRefused(PartyRefused::ROLE, 'roles');
            } elseif (!\in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        $registrationNumber = $this->registrationNumber($draft, $refusals);
        $vatId = $this->vatId($draft, $refusals);
        if (PartyKind::Company === $kind && !$registrationNumber instanceof RegistrationNumber && !$vatId instanceof VatId && !isset($refusals['registrationNumber']) && !isset($refusals['vatId'])) {
            $refusals['registrationNumber'] = new PartyRefused(PartyRefused::COMPANY_IDENTIFIER, 'registrationNumber');
        }
        if ([] !== $refusals || !$kind instanceof PartyKind) {
            return new ParsedParty(null, array_values($refusals));
        }

        return new ParsedParty(new PartyInput(
            $kind,
            $draft->displayName,
            $registrationNumber,
            $vatId,
            $draft->email,
            $draft->phone,
            $roles,
            new Address($draft->street, $draft->city, $draft->postalCode, $draft->countryCode),
            $draft->privateNote,
            $draft->mobile,
            $draft->website,
        ), []);
    }

    /**
     * A person linked to a company or a sole trader, with a position that fits.
     *
     * @throws PartyRefused
     */
    public function checkLink(Party $company, Party $person, string $position): void
    {
        if (PartyKind::Person === $company->kind() || PartyKind::Person !== $person->kind()) {
            throw new PartyRefused(PartyRefused::PERSON, 'personId');
        }
        if (mb_strlen($position) > self::MAX_POSITION) {
            throw new PartyRefused(PartyRefused::TOO_LONG, 'position');
        }
    }

    /**
     * @param array<string, PartyRefused> $refusals
     */
    private function required(array &$refusals, string $field, string $value, int $max): void
    {
        if ('' === $value) {
            $refusals[$field] = new PartyRefused(PartyRefused::REQUIRED, $field);
        } else {
            $this->longest($refusals, $field, $value, $max);
        }
    }

    /**
     * @param array<string, PartyRefused> $refusals
     */
    private function longest(array &$refusals, string $field, string $value, int $max): void
    {
        if (mb_strlen($value) > $max) {
            $refusals[$field] = new PartyRefused(PartyRefused::TOO_LONG, $field);
        }
    }

    /**
     * @param array<string, PartyRefused> $refusals
     */
    private function registrationNumber(PartyDraft $draft, array &$refusals): ?RegistrationNumber
    {
        if ('' === $draft->registrationNumber) {
            return null;
        }
        if (mb_strlen($draft->registrationNumber) > self::MAX_REGISTRATION_NUMBER) {
            $refusals['registrationNumber'] = new PartyRefused(PartyRefused::TOO_LONG, 'registrationNumber');

            return null;
        }
        if (!Countries::supports($draft->registrationCountry)) {
            $refusals['registrationNumber'] = new PartyRefused(PartyRefused::COUNTRY, 'registrationCountry');

            return null;
        }
        try {
            return $this->registrationNumbers->create($draft->registrationCountry, $draft->registrationNumber);
        } catch (InvalidRegistrationNumber) {
            $refusals['registrationNumber'] = new PartyRefused(PartyRefused::REGISTRATION_NUMBER, 'registrationNumber');

            return null;
        }
    }

    /**
     * @param array<string, PartyRefused> $refusals
     */
    private function vatId(PartyDraft $draft, array &$refusals): ?VatId
    {
        if ('' === $draft->vatId) {
            return null;
        }
        try {
            return VatId::fromString($draft->vatId);
        } catch (InvalidVatId) {
            $refusals['vatId'] = new PartyRefused(PartyRefused::VAT_ID, 'vatId');

            return null;
        }
    }
}
