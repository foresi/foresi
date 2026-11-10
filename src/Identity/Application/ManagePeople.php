<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Party;
use App\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What the owner changes about the people of the organisation: the role and the
 * contact a person represents, their hourly rate, whether they may still come
 * in, and their two-factor sign-in. The organisation always keeps an active owner.
 */
final readonly class ManagePeople
{
    public function __construct(
        private People $people,
        private PartyQuery $parties,
        private MembershipDirectory $memberships,
        private AuditActor $actor,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param ?Money $hourlyRate what the person's time is billed at when neither its project nor its client has a rate; null for none
     *
     * @throws PeopleRefused
     */
    public function change(string $membershipId, Role $role, ?string $contactId, ?Money $hourlyRate): void
    {
        $membership = $this->membership($membershipId);
        $party = null;
        if (null !== $contactId && '' !== $contactId) {
            $party = $this->parties->find($contactId) ?? throw new PeopleRefused('contact');
        }
        if (Role::Guest === $role && !$party instanceof Party) {
            throw new PeopleRefused('contact');
        }
        if (Role::Owner !== $role) {
            $this->keepAnOwner($membership);
        }
        $membership->changeRole($role, $party);
        $membership->changeHourlyRate($hourlyRate);
        $this->people->flush();
    }

    /**
     * @throws PeopleRefused
     */
    public function deactivate(string $membershipId): void
    {
        $membership = $this->membership($membershipId);
        if ($membership->user()->id() === $this->actor->userId()) {
            throw new PeopleRefused('self');
        }
        $this->keepAnOwner($membership);
        $membership->deactivate($this->clock->now());
        $this->people->flush();
    }

    /**
     * @throws PeopleRefused
     */
    public function reactivate(string $membershipId): void
    {
        $this->membership($membershipId)->reactivate();
        $this->people->flush();
    }

    /**
     * Only for a person who belongs to this organisation alone: an owner here
     * never weakens the sign-in to another organisation. The operator resets
     * anyone with app:user:reset-2fa.
     *
     * @throws PeopleRefused
     */
    public function resetTwoFactor(string $membershipId): void
    {
        $membership = $this->membership($membershipId);
        $user = $membership->user();
        if (\count($this->memberships->tenantIdsOf($user)) > 1) {
            throw new PeopleRefused('other_organisations');
        }
        $user->resetTwoFactor();
        $this->people->flush();
        $this->logger->notice('Two-factor sign-in was reset by an owner.', ['user' => $user->id(), 'by' => $this->actor->userId()]);
    }

    private function membership(string $id): Membership
    {
        return $this->people->membership($id) ?? throw new PeopleRefused('unknown');
    }

    private function keepAnOwner(Membership $membership): void
    {
        if (Role::Owner === $membership->role() && $membership->isActive() && $this->people->activeOwners() <= 1) {
            throw new PeopleRefused('last_owner');
        }
    }
}
