<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Directory;

use Foresi\Party\Application\Query\ContactRow;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\PartySummary;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyRole;

/**
 * The one place that turns a contact into the PartySummary other modules read, for the
 * directory (as the person signed in) and for the e-mail matching (with nobody signed in).
 */
final readonly class PartySummaryFactory
{
    /**
     * @param Address|null $address the billing address, else the first address
     */
    public function of(ContactRow $contact, ?Address $address, string $phone, string $mobile): PartySummary
    {
        return new PartySummary(
            $contact->id,
            ContactKind::from($contact->kind->value),
            $contact->name,
            $contact->registrationNumber,
            $contact->vatId,
            $this->lines($address),
            $contact->email,
            '' !== $phone ? $phone : ('' !== $mobile ? $mobile : null),
            array_map(static fn (PartyRole $role): string => $role->value, $contact->roles),
            $contact->archived,
            $contact->dueDays,
        );
    }

    /**
     * @return list<string>
     */
    private function lines(?Address $address): array
    {
        if (!$address instanceof Address) {
            return [];
        }

        return array_values(array_filter([trim($address->street), trim($address->postalCode.' '.$address->city), trim($address->countryCode)], static fn (string $line): bool => '' !== $line));
    }
}
