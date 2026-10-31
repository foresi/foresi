<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Application\PartyInput;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\Address;
use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads and checks the party form before anything is written, so the expected
 * mistakes (a missing name, a bad registration number) are form errors and
 * never exceptions out of a write.
 */
final readonly class PartySubmissionReader
{
    private const int MAX_NAME = 255;
    private const int MAX_PHONE = 64;
    private const int MAX_WEBSITE = 255;
    private const int MAX_STREET = 255;
    private const int MAX_CITY = 128;
    private const int MAX_POSTAL_CODE = 32;
    private const int MAX_EMAIL = 254;

    public function __construct(private RegistrationNumberFactory $registrationNumbers, private ValidatorInterface $validator)
    {
    }

    /**
     * @param PartyKind|null $fixedKind the kind of a party that exists already; it cannot change
     */
    public function read(Request $request, ?PartyKind $fixedKind = null): PartySubmission
    {
        $post = $request->request;
        $kind = $fixedKind ?? PartyKind::tryFrom($post->getString('kind'));
        $values = [
            'kind' => $kind instanceof PartyKind ? $kind->value : '',
            'displayName' => trim($post->getString('displayName')),
            'registrationCountry' => $this->country($post->getString('registrationCountry')),
            'registrationNumber' => trim($post->getString('registrationNumber')),
            'vatId' => trim($post->getString('vatId')),
            'email' => trim($post->getString('email')),
            'phone' => trim($post->getString('phone')),
            'mobile' => trim($post->getString('mobile')),
            'website' => trim($post->getString('website')),
            'roles' => $this->roleValues($post),
            'street' => trim($post->getString('street')),
            'city' => trim($post->getString('city')),
            'postalCode' => trim($post->getString('postalCode')),
            'countryCode' => $this->country($post->getString('countryCode')),
            'privateNote' => trim($post->getString('privateNote')),
        ];

        $errors = [];
        if (!$kind instanceof PartyKind) {
            $errors['kind'] = 'party.error.kind';
        }
        $this->require($errors, 'displayName', $values['displayName'], self::MAX_NAME);
        $this->require($errors, 'street', $values['street'], self::MAX_STREET);
        $this->require($errors, 'city', $values['city'], self::MAX_CITY);
        $this->require($errors, 'postalCode', $values['postalCode'], self::MAX_POSTAL_CODE);
        if (mb_strlen($values['phone']) > self::MAX_PHONE) {
            $errors['phone'] = 'party.error.too_long';
        }
        if (mb_strlen($values['mobile']) > self::MAX_PHONE) {
            $errors['mobile'] = 'party.error.too_long';
        }
        if (mb_strlen($values['website']) > self::MAX_WEBSITE) {
            $errors['website'] = 'party.error.too_long';
        }
        if (!CountryOptions::supports($values['countryCode'])) {
            $errors['countryCode'] = 'party.error.country';
        }
        if ('' !== $values['email'] && (mb_strlen($values['email']) > self::MAX_EMAIL || \count($this->validator->validate($values['email'], new Email())) > 0)) {
            $errors['email'] = 'party.error.email';
        }

        $registrationNumber = $this->registrationNumber($values, $errors);
        $vatId = $this->vatId($values, $errors);
        if (PartyKind::Company === $kind && !$registrationNumber instanceof RegistrationNumber && !$vatId instanceof VatId && !isset($errors['registrationNumber'], $errors['vatId'])) {
            $errors['registrationNumber'] = 'party.error.company_identifier';
        }

        if ([] !== $errors || !$kind instanceof PartyKind) {
            return new PartySubmission($values, $errors, null);
        }

        $roles = array_values(array_filter(array_map(PartyRole::tryFrom(...), $values['roles'])));

        return new PartySubmission($values, [], new PartyInput(
            $kind,
            $values['displayName'],
            $registrationNumber,
            $vatId,
            $values['email'],
            $values['phone'],
            $roles,
            new Address($values['street'], $values['city'], $values['postalCode'], $values['countryCode']),
            $values['privateNote'],
            $values['mobile'],
            $values['website'],
        ));
    }

    private function country(string $code): string
    {
        $code = strtoupper(trim($code));

        return '' === $code ? 'CZ' : $code;
    }

    /**
     * @param InputBag<bool|float|int|string> $post
     *
     * @return list<string>
     */
    private function roleValues(InputBag $post): array
    {
        $roles = [];
        foreach ($post->all('roles') as $role) {
            if (\is_string($role) && PartyRole::tryFrom($role) instanceof PartyRole) {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param array<string, string> $errors
     */
    private function require(array &$errors, string $field, string $value, int $max): void
    {
        if ('' === $value) {
            $errors[$field] = 'party.error.required';
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = 'party.error.too_long';
        }
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function registrationNumber(array $values, array &$errors): ?RegistrationNumber
    {
        $raw = $values['registrationNumber'];
        \assert(\is_string($raw) && \is_string($values['registrationCountry']));
        if ('' === $raw) {
            return null;
        }
        if (!CountryOptions::supports($values['registrationCountry'])) {
            $errors['registrationNumber'] = 'party.error.country';

            return null;
        }

        try {
            return $this->registrationNumbers->create($values['registrationCountry'], $raw);
        } catch (InvalidRegistrationNumber) {
            $errors['registrationNumber'] = 'party.error.registration_number';

            return null;
        }
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function vatId(array $values, array &$errors): ?VatId
    {
        $raw = $values['vatId'];
        \assert(\is_string($raw));
        if ('' === $raw) {
            return null;
        }

        try {
            return VatId::fromString($raw);
        } catch (InvalidVatId) {
            $errors['vatId'] = 'party.error.vat_id';

            return null;
        }
    }
}
