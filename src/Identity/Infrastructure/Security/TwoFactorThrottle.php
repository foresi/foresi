<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Scheb\TwoFactorBundle\Security\Http\EventListener\CheckBackupCodeListener;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Five code attempts per user in five minutes. The firewall's login throttling
 * also counts them, but a correct password resets that one; this one only a
 * completed sign-in resets, so signing in again buys no more guesses.
 */
final readonly class TwoFactorThrottle
{
    public function __construct(private RateLimiterFactory $twoFactorCodeLimiter)
    {
    }

    // Before any code, authenticator or recovery, is checked.
    #[AsEventListener(event: CheckPassportEvent::class, priority: CheckBackupCodeListener::LISTENER_PRIORITY + 16, dispatcher: 'security.event_dispatcher.main')]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $credentials = $event->getPassport()->getBadge(TwoFactorCodeCredentials::class);
        if (!$credentials instanceof TwoFactorCodeCredentials) {
            return;
        }
        $limit = $this->twoFactorCodeLimiter->create($credentials->getTwoFactorToken()->getUserIdentifier())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyLoginAttemptsAuthenticationException((int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));
        }
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::COMPLETE)]
    public function onComplete(TwoFactorAuthenticationEvent $event): void
    {
        $this->twoFactorCodeLimiter->create($event->getToken()->getUserIdentifier())->reset();
    }
}
