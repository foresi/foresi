<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\InvitationToken;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Application\SystemMailUnavailable;
use Psr\Clock\ClockInterface;

/**
 * The owner invites a person by e-mail: a member or a guest, linked to the
 * contact they represent when the owner picks one. A pending invitation of the same address gives way to the
 * new one; someone who already belongs to the organisation is not invited again.
 * Without system mail the link is handed to the owner instead.
 */
final readonly class InvitePeople
{
    public function __construct(
        private Invitations $invitations,
        private People $people,
        private UserRepository $users,
        private PartyQuery $parties,
        private InvitationMailer $mailer,
        private MembershipDirectory $memberships,
        private AuditActor $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvitationRefused
     */
    public function invite(string $email, string $name, Role $role, ?string $contactId, string $locale): InvitationIssued
    {
        $email = strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new InvitationRefused('email');
        }
        if ('' === trim($name)) {
            throw new InvitationRefused('name');
        }
        if (Role::Owner === $role) {
            throw new InvitationRefused('role');
        }
        $party = $this->contact($contactId);
        $user = $this->users->findByEmail($email);
        if ($user instanceof User && $this->people->membershipOf($user->id()) instanceof \Foresi\Identity\Domain\Membership) {
            throw new InvitationRefused('member');
        }

        $now = $this->clock->now();
        foreach ($this->invitations->pendingFor($email, $now) as $pending) {
            $pending->revoke($now);
        }
        $token = InvitationToken::generate();
        $invitation = new Invitation($email, $name, $role, $party, $this->inviterId(), $locale, InvitationToken::hash($token), $now);
        $this->invitations->add($invitation);
        $this->invitations->flush();

        return $this->issued($invitation, $token);
    }

    /**
     * A new link, valid for another week, sent again; the old link stops working.
     *
     * @throws InvitationRefused
     */
    public function resend(string $id): InvitationIssued
    {
        $invitation = $this->invitations->find($id) ?? throw new InvitationRefused('unknown');
        $now = $this->clock->now();
        try {
            $token = InvitationToken::generate();
            $invitation->renew(InvitationToken::hash($token), $now);
        } catch (\DomainException) {
            throw new InvitationRefused('not_pending');
        }
        $this->invitations->flush();

        return $this->issued($invitation, $token);
    }

    /**
     * @throws InvitationRefused
     */
    public function revoke(string $id): void
    {
        $invitation = $this->invitations->find($id) ?? throw new InvitationRefused('unknown');
        $invitation->revoke($this->clock->now());
        $this->invitations->flush();
    }

    private function issued(Invitation $invitation, string $token): InvitationIssued
    {
        $link = $this->mailer->link($token);
        if (!$this->mailer->isConfigured()) {
            return new InvitationIssued($invitation, $link, false);
        }
        try {
            $this->mailer->send($invitation, $token, $this->inviterName(), $this->organisation());
        } catch (SystemMailUnavailable) {
            return new InvitationIssued($invitation, $link, false);
        }

        return new InvitationIssued($invitation, $link, true);
    }

    private function contact(?string $id): ?Party
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return $this->parties->find($id) ?? throw new InvitationRefused('contact');
    }

    private function inviterId(): string
    {
        return $this->actor->userId() ?? throw new \LogicException('Only a signed-in owner invites.');
    }

    private function inviterName(): string
    {
        $user = $this->users->findById($this->inviterId());

        return $user instanceof User ? $user->displayName() : '';
    }

    private function organisation(): string
    {
        return $this->memberships->organisationOf($this->inviterId())->name ?? '';
    }
}
