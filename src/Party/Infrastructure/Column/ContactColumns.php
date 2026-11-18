<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Column;

use Foresi\Party\Application\PartyRow;
use Foresi\Party\Domain\PartyRole;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the contact list; each cell renders one PartyRow. The name and the e-mail give
 * way, cut short with their full text in a title. A phone shows the name and the state; the rest
 * goes in the line under the name.
 */
final readonly class ContactColumns implements ListColumns
{
    public const string LIST = 'party.contacts';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Column('name', 'party.column.name', static fn (PartyRow $row): string => $row->displayName, defaultPosition: 10, sortKey: 'name', locked: true, truncate: true),
            new Column('kind', 'party.column.kind', fn (PartyRow $row): string => $this->translator->trans('party.kind.'.$row->kind->value), defaultPosition: 20, sortKey: 'kind', phone: false),
            new Column('roles', 'party.column.roles', $this->roles(...), defaultPosition: 30, phone: false),
            new Column('registration', 'party.column.registration', static fn (PartyRow $row): ?string => $row->registrationNumber, defaultPosition: 40, sortKey: 'registration', phone: false),
            new Column('vat', 'party.column.vat', static fn (PartyRow $row): ?string => $row->vatId, defaultPosition: 50, sortKey: 'vat', phone: false),
            new Column('email', 'party.column.email', static fn (PartyRow $row): ?string => $row->email, defaultPosition: 60, phone: false, truncate: true),
            new Column('status', 'party.column.status', $this->status(...), defaultPosition: 70),
        ];
    }

    public function roles(PartyRow $row): string
    {
        return implode(', ', array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), $row->roles));
    }

    /**
     * @return array{label: string, tone: string}|null
     */
    private function status(PartyRow $row): ?array
    {
        return match (true) {
            $row->unreliablePayer => ['label' => $this->translator->trans('registry.result.unreliable'), 'tone' => 'red'],
            $row->archived => ['label' => $this->translator->trans('party.archived'), 'tone' => 'gray'],
            default => null,
        };
    }
}
