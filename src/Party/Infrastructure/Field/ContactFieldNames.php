<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Field;

use Foresi\Party\Application\PartyQuery;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The names of the contacts a contact field points to, remembered for the request.
 */
final class ContactFieldNames implements FieldReferenceNames, ResetInterface
{
    /** @var array<string, string> */
    private array $names = [];

    public function __construct(private readonly PartyQuery $parties)
    {
    }

    public function kind(): FieldKind
    {
        return FieldKind::Contact;
    }

    public function names(array $ids): array
    {
        $missing = array_values(array_diff($ids, array_keys($this->names)));
        if ([] !== $missing) {
            foreach ($this->parties->findMany($missing) as $party) {
                $this->names[$party->id()] = $party->displayName();
            }
        }

        return array_intersect_key($this->names, array_flip($ids));
    }

    public function reset(): void
    {
        $this->names = [];
    }
}
