<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationState;
use App\Identity\Domain\InvitationToken;
use App\Identity\Domain\Membership;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Psr\Clock\ClockInterface;

/**
 * The invited person follows the link: a new person sets a name and a password,
 * someone with an account signs in first; either way the invitation becomes a
 * membership once. The link activates the organisation it belongs to, for this
 * request only.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private Invitations $invitations,
        private People $people,
        private UserRepository $users,
        private TenantContext $context,
        private ClockInterface $clock,
    ) {
    }

    public function open(string $token): ?OpenInvitation
    {
        if (!InvitationToken::isWellFormed($token)) {
            return null;
        }
        $hash = InvitationToken::hash($token);
        $tenant = $this->invitations->tenantOfToken($hash);
        if (!$tenant instanceof InvitationTenant) {
            return null;
        }
        $this->activate($tenant->id);
        $invitation = $this->invitations->byTokenHash($hash);
        if (!$invitation instanceof Invitation) {
            return null;
        }

        return new OpenInvitation($invitation, $tenant->name, $invitation->state($this->clock->now()), $this->users->findByEmail($invitation->email()) instanceof User);
    }

    /**
     * @throws InvitationRefused
     */
    public function asNewUser(string $token, string $name, string $passwordHash): User
    {
        $open = $this->pending($token);
        if ($open->hasAccount) {
            throw new InvitationRefused('user_exists');
        }
        $invitation = $open->invitation;
        $user = new User($invitation->email(), $passwordHash, '' === trim($name) ? $invitation->displayName() : $name, $invitation->locale());
        $this->join($user, $invitation);

        return $user;
    }

    /**
     * @throws InvitationRefused
     */
    public function asUser(string $token, string $userId): TenantId
    {
        $open = $this->pending($token);
        $user = $this->users->findById($userId);
        if (!$user instanceof User || $user->email() !== $open->invitation->email()) {
            throw new InvitationRefused('other_user');
        }
        if ($this->people->membershipOf($user->id()) instanceof Membership) {
            throw new InvitationRefused('member');
        }
        $this->join($user, $open->invitation);

        return $this->context->current();
    }

    private function pending(string $token): OpenInvitation
    {
        $open = $this->open($token) ?? throw new InvitationRefused('unknown');
        if (InvitationState::Pending !== $open->state) {
            throw new InvitationRefused('not_pending');
        }

        return $open;
    }

    private function join(User $user, Invitation $invitation): void
    {
        $invitation->accept($this->clock->now());
        $this->people->add(new Membership($user, $invitation->role(), $invitation->party()));
        // One flush: the account, the membership and the used invitation together.
        $this->users->save($user);
    }

    private function activate(TenantId $tenant): void
    {
        if (!$this->context->has() || !$this->context->current()->equals($tenant)) {
            $this->context->switchTo($tenant);
        }
    }
}
