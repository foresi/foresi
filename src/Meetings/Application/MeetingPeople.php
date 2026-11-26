<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Domain\Membership;

/**
 * The people of the firm a meeting or a series can hold: the active owners and
 * members, never a guest, by membership. Read once a request.
 */
final class MeetingPeople
{
    /** @var array<string, Person>|null by membership id */
    private ?array $staff = null;

    /** @var array<string, string>|null names of every membership, a former one too */
    private ?array $names = null;

    public function __construct(private readonly People $people, private readonly AuditActor $actor)
    {
    }

    /**
     * @return array<string, Person> the active owners and members by membership id, by name
     */
    public function staff(): array
    {
        if (null === $this->staff) {
            $this->staff = [];
            foreach ($this->people->all() as $person) {
                if ($person->active && $person->role->isStaff()) {
                    $this->staff[$person->membershipId] = $person;
                }
            }
        }

        return $this->staff;
    }

    public function isStaff(string $membershipId): bool
    {
        return isset($this->staff()[$membershipId]);
    }

    /**
     * The name of a membership, a former one too, or null for an unknown one.
     */
    public function name(string $membershipId): ?string
    {
        if (null === $this->names) {
            $this->names = [];
            foreach ($this->people->all() as $person) {
                $this->names[$person->membershipId] = $person->name;
            }
        }

        return $this->names[$membershipId] ?? null;
    }

    /**
     * The membership of the person signed in, or null for none.
     */
    public function me(): ?string
    {
        $userId = $this->actor->userId();
        $membership = null === $userId ? null : $this->people->membershipOf($userId);

        return $membership instanceof Membership ? $membership->id() : null;
    }

    /**
     * The membership of a user, or null for none.
     */
    public function of(string $userId): ?string
    {
        return $this->people->membershipOf($userId)?->id();
    }
}
