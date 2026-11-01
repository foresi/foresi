<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Application\LegalEntityInput;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\Address;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\InvalidVatId;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and checks the firm form before anything is written, like the contact
 * form: an expected mistake is a form error, never an exception out of a write.
 */
final readonly class LegalEntitySubmissionReader
{
    /** The length limits of the columns. */
    private const array REQUIRED = ['name' => 255, 'street' => 255, 'city' => 128, 'postalCode' => 32];
    private const int MAX_REGISTER_ENTRY = 255;

    public function __construct(private RegistrationNumberFactory $registrationNumbers)
    {
    }

    public function read(Request $request): LegalEntitySubmission
    {
        $post = $request->request;
        $values = [
            'name' => trim($post->getString('name')),
            'registrationCountry' => $this->country($post->getString('registrationCountry')),
            'registrationNumber' => trim($post->getString('registrationNumber')),
            'vatStatus' => $post->getString('vatStatus'),
            'vatId' => trim($post->getString('vatId')),
            'street' => trim($post->getString('street')),
            'city' => trim($post->getString('city')),
            'postalCode' => trim($post->getString('postalCode')),
            'countryCode' => $this->country($post->getString('countryCode')),
            'registerEntry' => trim($post->getString('registerEntry')),
            'bankAccounts' => trim($post->getString('bankAccounts')),
        ];

        $errors = [];
        foreach (self::REQUIRED as $field => $max) {
            if ('' === $values[$field]) {
                $errors[$field] = ['party.error.required'];
            } elseif (mb_strlen($values[$field]) > $max) {
                $errors[$field] = ['party.error.too_long'];
            }
        }
        if (mb_strlen($values['registerEntry']) > self::MAX_REGISTER_ENTRY) {
            $errors['registerEntry'] = ['party.error.too_long'];
        }
        if (!CountryOptions::supports($values['countryCode'])) {
            $errors['countryCode'] = ['party.error.country'];
        }
        $status = VatStatus::tryFrom($values['vatStatus']);
        if (!$status instanceof VatStatus) {
            $errors['vatStatus'] = ['firm.error.vat_status'];
        }

        $registrationNumber = $this->registrationNumber($values, $errors);
        $vatId = $this->vatId($values, $status, $errors);
        $accounts = $this->bankAccounts($values['bankAccounts'], $errors);

        if ([] !== $errors || !$registrationNumber instanceof RegistrationNumber || !$status instanceof VatStatus) {
            return new LegalEntitySubmission($values, $errors, null);
        }

        return new LegalEntitySubmission($values, [], new LegalEntityInput(
            $values['name'],
            $registrationNumber,
            $vatId,
            new Address($values['street'], $values['city'], $values['postalCode'], $values['countryCode']),
            $status,
            $values['registerEntry'],
            $accounts,
        ));
    }

    private function country(string $code): string
    {
        $code = strtoupper(trim($code));

        return '' === $code ? 'CZ' : $code;
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function registrationNumber(array $values, array &$errors): ?RegistrationNumber
    {
        if ('' === $values['registrationNumber']) {
            $errors['registrationNumber'] = ['party.error.required'];

            return null;
        }
        if (!CountryOptions::supports($values['registrationCountry'])) {
            $errors['registrationNumber'] = ['party.error.country'];

            return null;
        }

        try {
            return $this->registrationNumbers->create($values['registrationCountry'], $values['registrationNumber']);
        } catch (InvalidRegistrationNumber) {
            $errors['registrationNumber'] = ['party.error.registration_number'];

            return null;
        }
    }

    /**
     * A non-payer has no VAT number to show; a payer and an identified person do.
     *
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function vatId(array $values, ?VatStatus $status, array &$errors): ?VatId
    {
        if ('' === $values['vatId']) {
            if ($status instanceof VatStatus && $status->requiresVatId()) {
                $errors['vatId'] = ['firm.error.vat_id_required'];
            }

            return null;
        }

        try {
            return VatId::fromString($values['vatId']);
        } catch (InvalidVatId) {
            $errors['vatId'] = ['party.error.vat_id'];

            return null;
        }
    }

    /**
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     *
     * @return list<string>
     */
    private function bankAccounts(string $text, array &$errors): array
    {
        $accounts = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            try {
                $accounts[] = Iban::fromString($line)->toString();
            } catch (InvalidIban) {
                $errors['bankAccounts'] = ['firm.error.iban', ['iban' => $line]];

                return [];
            }
        }

        return $accounts;
    }
}
