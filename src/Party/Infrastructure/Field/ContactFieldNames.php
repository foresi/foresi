<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Field;

use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The contacts a contact field points to: their names, remembered for the request,
 * the active contacts that match a typed text, as the address book searches, and
 * the page of each.
 */
final class ContactFieldNames implements FieldReferenceNames, ResetInterface
{
    /** @var array<string, string> */
    private array $names = [];

    public function __construct(private readonly PartyQuery $parties, private readonly UrlGeneratorInterface $urls)
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

    public function matches(string $text, int $limit): array
    {
        $rows = \array_slice($this->parties->search($text, null, false, 'name', 1)->rows, 0, max(0, $limit));
        foreach ($rows as $row) {
            $this->names[$row->id] = $row->displayName;
        }

        return array_map(static fn (PartyRow $row): array => ['id' => $row->id, 'label' => $row->displayName, 'hint' => $row->email ?? $row->registrationNumber], $rows);
    }

    public function url(string $id): string
    {
        return $this->urls->generate('app_contact_show', ['id' => $id]);
    }

    public function reset(): void
    {
        $this->names = [];
    }
}
