<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\EmailChangeToken;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Psr\Clock\ClockInterface;

/**
 * A person changes the address they sign in with (OWASP, Pipedrive): a link goes
 * to the new address and works for a day; until it is opened the old address
 * stays the one to sign in with. Once it is, the address switches, every other
 * session ends and the old address gets a notice naming the firm's owners. The
 * old address is not asked to confirm too, so someone who lost that mailbox can
 * still move on. No two people share an address: it is checked when the change
 * starts and again when it is confirmed.
 */
final readonly class ChangeEmail
{
    public function __construct(
        private UserRepository $users,
        private People $people,
        private EmailChangeMail $mail,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return \DateTimeImmutable when the link stops working
     *
     * @throws EmailChangeRefused
     */
    public function start(string $userId, string $newEmail): \DateTimeImmutable
    {
        $user = $this->user($userId);
        $newEmail = strtolower(trim($newEmail));
        if (false === filter_var($newEmail, \FILTER_VALIDATE_EMAIL)) {
            throw new EmailChangeRefused(EmailChangeRefused::INVALID);
        }
        if ($newEmail === $user->email()) {
            throw new EmailChangeRefused(EmailChangeRefused::SAME);
        }
        $this->assertFree($newEmail, $user);
        if (!$this->mail->isConfigured()) {
            throw new EmailChangeRefused(EmailChangeRefused::MAIL_UNAVAILABLE);
        }
        $token = EmailChangeToken::generate();
        $expiresAt = $this->clock->now()->modify(\sprintf('+%d hours', User::EMAIL_CHANGE_VALID_HOURS));
        $user->requestEmailChange($newEmail, EmailChangeToken::hash($token), $expiresAt);
        $this->users->save($user);
        $this->mail->sendConfirmation($user, $newEmail, $token, $expiresAt);

        return $expiresAt;
    }

    /**
     * @throws EmailChangeRefused
     */
    public function cancel(string $userId): void
    {
        $user = $this->user($userId);
        $user->cancelEmailChange();
        $this->users->save($user);
    }

    /**
     * The person whose change the link confirms, while it still works.
     */
    public function open(#[\SensitiveParameter] string $token): ?User
    {
        if (!EmailChangeToken::isWellFormed($token)) {
            return null;
        }
        $user = $this->users->findByPendingEmailToken(EmailChangeToken::hash($token));

        return $user instanceof User && null !== $user->pendingEmail($this->clock->now()) ? $user : null;
    }

    /**
     * @throws EmailChangeRefused
     */
    public function confirm(#[\SensitiveParameter] string $token): EmailChanged
    {
        $user = $this->open($token) ?? throw new EmailChangeRefused(EmailChangeRefused::LINK_UNKNOWN);
        $now = $this->clock->now();
        $this->assertFree((string) $user->pendingEmail($now), $user);
        $oldEmail = $user->confirmEmailChange($now);
        $this->users->save($user);
        $this->mail->sendNotice($user, $oldEmail, $now, $this->ownersBesides($user));

        return new EmailChanged($user, $oldEmail);
    }

    private function user(string $userId): User
    {
        return $this->users->findById($userId) ?? throw new EmailChangeRefused(EmailChangeRefused::UNKNOWN_USER);
    }

    private function assertFree(string $email, User $user): void
    {
        $holder = $this->users->findByEmail($email);
        if ($holder instanceof User && $holder->id() !== $user->id()) {
            throw new EmailChangeRefused(EmailChangeRefused::TAKEN);
        }
    }

    /**
     * @return list<string>
     */
    private function ownersBesides(User $user): array
    {
        $owners = [];
        foreach ($this->people->all() as $person) {
            if ($person->active && Role::Owner === $person->role && $person->userId !== $user->id()) {
                $owners[] = $person->name;
            }
        }

        return $owners;
    }
}
