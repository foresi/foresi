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
        return $this->invitation instanceof Invitation ? $this->invitation->id() : $this->held()->membershipId;
    }

    public function name(): string
    {
        return $this->invitation instanceof Invitation ? $this->invitation->displayName() : $this->held()->name;
    }

    public function email(): string
    {
        return $this->invitation instanceof Invitation ? $this->invitation->email() : $this->held()->email;
    }

    public function role(): Role
    {
        return $this->invitation instanceof Invitation ? $this->invitation->role() : $this->held()->role;
    }

    /**
     * @return array{id: string, name: string}|null the contact the person or the invitation is linked to
     */
    public function contact(): ?array
    {
        if ($this->invitation instanceof Invitation) {
            $party = $this->invitation->party();

            return $party instanceof Party ? ['id' => $party->id(), 'name' => $party->displayName()] : null;
        }
        $person = $this->held();

        return null === $person->contactId ? null : ['id' => $person->contactId, 'name' => $person->contactName ?? ''];
    }

    /**
     * The person of a row that is no invitation: the constructor keeps exactly one of the two.
     */
    private function held(): Person
    {
        \assert($this->person instanceof Person);

        return $this->person;
    }
}
