<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\Person;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;

/**
 * One row of the list of people: a person who belongs to the organisation, or a pending
 * invitation of one.
 */
final readonly class PeopleRow
{
    private function __construct(public ?Person $person, public ?Invitation $invitation)
    {
    }

    public static function person(Person $person): self
    {
        return new self($person, null);
    }

    public static function invitation(Invitation $invitation): self
    {
        return new self(null, $invitation);
    }

    public function isInvitation(): bool
    {
        return $this->invitation instanceof Invitation;
    }

    public function id(): string
    {
        return $this->person->membershipId ?? $this->invitation?->id() ?? '';
    }

    public function name(): string
    {
        return $this->person->name ?? $this->invitation?->displayName() ?? '';
    }

    public function email(): string
    {
        return $this->person->email ?? $this->invitation?->email() ?? '';
    }

    public function role(): Role
    {
        return $this->person->role ?? $this->invitation?->role() ?? Role::Member;
    }

    /**
     * @return array{id: string, name: string}|null the contact the person or the invitation is linked to
     */
    public function contact(): ?array
    {
        if ($this->person instanceof Person) {
            return null === $this->person->contactId ? null : ['id' => $this->person->contactId, 'name' => $this->person->contactName ?? ''];
        }
        $party = $this->invitation?->party();

        return $party instanceof Party ? ['id' => $party->id(), 'name' => $party->displayName()] : null;
    }
}
