<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Documents;

use Foresi\Documents\Contract\DocumentVariableSource;
use Foresi\Documents\Contract\VariableDefinition;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contact a document links to under {{ client.* }}: the first link of kind
 * "contact". Without one, or when that contact is archived or gone, the source
 * does not apply and Documents names the client variables the template misses.
 * The persons are the contact persons of a company in the order they were linked.
 */
final readonly class ClientVariables implements DocumentVariableSource
{
    public function __construct(private PartyDirectory $directory, private TranslatorInterface $translator)
    {
    }

    public function prefix(): string
    {
        return 'client';
    }

    public function definitions(): array
    {
        return [
            $this->definition('name', 'Example Client s.r.o.'),
            $this->definition('address_lines', [['line' => 'Client Street 2'], ['line' => '200 00 Client City'], ['line' => 'CZ']]),
            $this->definition('registration_number', '87654321'),
            $this->definition('vat_id', 'CZ87654321'),
            $this->definition('persons', [['name' => 'Jana Example', 'position' => 'Director', 'email' => 'jana@example.com', 'phone' => '+420 000 000 000']]),
        ];
    }

    public function values(array $links): ?array
    {
        $contact = null;
        foreach ($links as $link) {
            if (ContactLinkTarget::KIND === $link->kind) {
                $contact = $this->directory->find($link->id);
                break;
            }
        }
        if (!$contact instanceof \Foresi\Party\Contract\PartySummary || $contact->archived) {
            return null;
        }

        return [
            'name' => $contact->displayName,
            'address_lines' => AddressLines::rows($contact->addressLines),
            'registration_number' => $contact->registrationNumber ?? '',
            'vat_id' => $contact->vatId ?? '',
            'persons' => array_map(static fn (PartyPersonSummary $person): array => [
                'name' => $person->displayName,
                'position' => $person->position ?? '',
                'email' => $person->email ?? '',
                'phone' => $person->phone ?? '',
            ], $this->directory->personsOf($contact->id)),
        ];
    }

    /**
     * @param string|list<array<string, string>> $example
     */
    private function definition(string $name, string|array $example): VariableDefinition
    {
        return new VariableDefinition($name, $this->translator->trans('party.document_variable.client.'.$name), $example);
    }
}
