<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Application\PartyDraft;
use App\Party\Application\PartyRefused;
use App\Party\Application\PartyRules;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the party form and checks it with the rules of the address book before
 * anything is written, so the expected mistakes (a missing name, a bad
 * registration number) are form errors and never exceptions out of a write.
 * The rules answer reasons; the form shows each as its message at its field.
 */
final readonly class PartySubmissionReader
{
    /** The message of each reason of PartyRules, as the form shows it. */
    public const array MESSAGES = [
        PartyRefused::REQUIRED => 'party.error.required',
        PartyRefused::TOO_LONG => 'party.error.too_long',
        PartyRefused::KIND => 'party.error.kind',
        PartyRefused::COUNTRY => 'party.error.country',
        PartyRefused::EMAIL => 'party.error.email',
        PartyRefused::REGISTRATION_NUMBER => 'party.error.registration_number',
        PartyRefused::VAT_ID => 'party.error.vat_id',
        PartyRefused::COMPANY_IDENTIFIER => 'party.error.company_identifier',
        PartyRefused::PERSON => 'party.contacts.person_invalid',
        PartyRefused::LINKED => 'party.contacts.person_invalid',
    ];

    public function __construct(private PartyRules $rules)
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

        $parsed = $this->rules->parse(new PartyDraft(
            $values['kind'],
            $values['displayName'],
            $values['registrationCountry'],
            $values['registrationNumber'],
            $values['vatId'],
            $values['email'],
            $values['phone'],
            $values['mobile'],
            $values['website'],
            $values['roles'],
            $values['street'],
            $values['city'],
            $values['postalCode'],
            $values['countryCode'],
            $values['privateNote'],
        ));
        $errors = [];
        foreach ($parsed->refusals as $refused) {
            // The country of a registration number is chosen next to the number, which shows its problem.
            $field = 'registrationCountry' === $refused->field ? 'registrationNumber' : (string) $refused->field;
            $errors[$field] = self::MESSAGES[$refused->reason] ?? 'party.error.save_failed';
        }

        return new PartySubmission($values, $errors, $parsed->input);
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
}
