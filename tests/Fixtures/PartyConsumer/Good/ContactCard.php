<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Fixtures\PartyConsumer\Good;

use Foresi\Contacts\Contract\ContactTabs;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\UnknownParty;

/**
 * A module that shows a contact and its persons through the Party contract alone.
 */
final readonly class ContactCard
{
    public function __construct(private PartyDirectory $parties)
    {
    }

    /**
     * @return array{slot: string, name: string, persons: list<string>}
     */
    public function of(string $id): array
    {
        try {
            $party = $this->parties->get($id);
        } catch (UnknownParty) {
            return ['slot' => ContactTabs::SLOT, 'name' => '', 'persons' => []];
        }

        return [
            'slot' => ContactTabs::SLOT,
            'name' => $party->displayName,
            'persons' => array_map(static fn (PartyPersonSummary $person): string => $person->displayName, $this->parties->personsOf($party->id)),
        ];
    }
}
