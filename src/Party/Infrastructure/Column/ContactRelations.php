<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Column;

use Foresi\Party\Contract\Contacts;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Ui\Contract\Relation;
use Foresi\Ui\Contract\RelationSource;

/**
 * The contact persons of a contact (spec DS6): the contact list filters by having some, or one
 * of a given name.
 */
final readonly class ContactRelations implements RelationSource
{
    public function relations(string $list): iterable
    {
        if (Contacts::LIST !== $list) {
            return [];
        }

        return [new Relation('contact_persons', 'party.relation.contact_persons', ContactLink::class.' cl, '.Party::class.' cp WHERE cl.company = '.Contacts::ALIAS.' AND cl.person = cp', name: 'cp.displayName', nameLabel: 'party.column.name')];
    }
}
