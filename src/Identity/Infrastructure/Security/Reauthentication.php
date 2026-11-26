<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\PasswordHashing;
use Foresi\Identity\Application\UserRepository;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\ReusedTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Asks a signed-in person to show it is still them before a change to how they
 * sign in or to what can act as them (GitHub sudo mode, OWASP re-authentication).
 * With two-factor sign-in they give a code from their app, else their password.
 * A success, or a sign-in, opens a window of WINDOW seconds in their session:
 * shorter than GitHub's two hours, as Foresi holds a firm's finances.
 *
 * A code goes through the bundle's reuse cache like a sign-in code, so one read
 * over a shoulder works once only. Failed attempts are counted per person, five
 * in five minutes, the same limit as the codes of the sign-in; the current
 * password of a password change counts in the same budget.
 */
final readonly class Reauthentication
{
    public const int WINDOW = 900;
    private const string SESSION_KEY = 'identity.reauthenticated_at';

    public function __construct(
        private UserRepository $users,
        private PasswordHashing $hashing,
        private TotpAuthenticatorInterface $totp,
        private EventDispatcherInterface $events,
        private RateLimiterFactory $reauthenticationLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function isFresh(SessionInterface $session): bool
    {
        $at = $session->get(self::SESSION_KEY);
        $now = $this->clock->now()->getTimestamp();

        return \is_int($at) && $at <= $now && $now - $at < self::WINDOW;
    }

    public function markFresh(SessionInterface $session): void
    {
        $session->set(self::SESSION_KEY, $this->clock->now()->getTimestamp());
    }

    /**
     * Checks the password, or with two-factor sign-in the code, and opens the window.
     *
     * @throws ReauthenticationFailed
     */
    public function confirm(SecurityUser $user, #[\SensitiveParameter] string $secret, SessionInterface $session): void
    {
        $this->guardAttempt($user->id());
        if ($user->hasTwoFactor()) {
            $this->checkCode($user, preg_replace('/\s+/', '', $secret) ?? '');
        } elseif (!$this->hashing->verify($this->users->findById($user->id())?->passwordHash() ?? '', $secret)) {
            $this->failedAttempt($user->id());
            throw ReauthenticationFailed::passwordWrong();
        }
        $this->succeededAttempt($user->id());
        $this->markFresh($session);
    }

    /**
     * @throws ReauthenticationFailed when the person has used up their attempts
     */
    public function guardAttempt(string $userId): void
    {
        $limit = $this->reauthenticationLimiter->create($userId)->consume(0);
        if (0 === $limit->getRemainingTokens()) {
            throw ReauthenticationFailed::throttled((int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));
        }
    }

    public function failedAttempt(string $userId): void
    {
        $this->reauthenticationLimiter->create($userId)->consume();
    }

    public function succeededAttempt(string $userId): void
    {
        $this->reauthenticationLimiter->create($userId)->reset();
    }

    private function checkCode(SecurityUser $user, string $code): void
    {
        try {
            $this->events->dispatch(new TwoFactorCodeEvent($user, $code), TwoFactorAuthenticationEvents::CHECK);
        } catch (ReusedTwoFactorCodeException) {
            // The right code, seen once already: not a guess, so it costs no attempt.
            throw ReauthenticationFailed::codeReused();
        }
        if ('' === $code || !$this->totp->checkCode($user, $code)) {
            $this->failedAttempt($user->id());
            throw ReauthenticationFailed::codeWrong();
        }
        $this->events->dispatch(new TwoFactorCodeEvent($user, $code), TwoFactorAuthenticationEvents::CODE_VALID);
    }
}
