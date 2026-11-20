<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mail;

use Foresi\Invoicing\Application\SendLimits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * 30 sends a minute per user and 500 a day for the instance, as configured in
 * framework.yaml. A send without a signed-in user is the system's, made by a
 * daily run in the worker: the minute budget is there to slow a person down,
 * so it spends only the day of the instance, which still bounds it.
 * It stops at SYSTEM_SHARE of that day, so a run that would take all of it
 * leaves people room to send by hand.
 */
final readonly class RateLimitedSends implements SendLimits
{
    /** The part of the daily budget the system's sends may take, in percent. */
    public const int SYSTEM_SHARE = 80;
    /** The one key of the daily budget, the instance's. */
    private const string DAY_KEY = 'instance';

    public function __construct(
        #[Autowire(service: 'limiter.invoice_send')]
        private RateLimiterFactoryInterface $perUser,
        #[Autowire(service: 'limiter.invoice_send_firm')]
        private RateLimiterFactoryInterface $daily,
    ) {
    }

    public function take(?string $userId): bool
    {
        if (null !== $userId && !$this->perUser->create($userId)->consume()->isAccepted()) {
            return false;
        }

        $day = $this->daily->create(self::DAY_KEY);
        if (null === $userId) {
            // Peeks without spending: the system stops while the rest of the day is left to people.
            $left = $day->consume(0);
            if ($left->getRemainingTokens() * 100 <= $left->getLimit() * (100 - self::SYSTEM_SHARE)) {
                return false;
            }
        }

        return $day->consume()->isAccepted();
    }
}
