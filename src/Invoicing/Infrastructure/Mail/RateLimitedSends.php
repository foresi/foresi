<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\SendLimits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * 30 sends a minute per user and 500 a day per firm, as configured in
 * framework.yaml. Work without a signed-in user shares one budget.
 */
final readonly class RateLimitedSends implements SendLimits
{
    public function __construct(
        #[Autowire(service: 'limiter.invoice_send')]
        private RateLimiterFactoryInterface $perUser,
        #[Autowire(service: 'limiter.invoice_send_firm')]
        private RateLimiterFactoryInterface $perFirm,
    ) {
    }

    public function take(?string $userId, string $firmId): bool
    {
        if (!$this->perUser->create($userId ?? 'nobody')->consume()->isAccepted()) {
            return false;
        }

        return $this->perFirm->create($firmId)->consume()->isAccepted();
    }
}
