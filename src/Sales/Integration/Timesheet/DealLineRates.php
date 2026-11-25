<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Timesheet;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Contract\ProjectRateSource;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The rate of the hourly deal line a project is linked to, which Timesheet asks
 * before the project's own rate. An open or a won deal gives it, so time logged
 * while the deal is still negotiated takes the rate offered; a lost deal or one
 * in the trash gives none, and the time falls back to the project's rate.
 *
 * Timesheet asks once for each person's time on a project, so the answer is
 * kept per project for the request (reset between requests and messages).
 */
final class DealLineRates implements ProjectRateSource, ResetInterface
{
    /** @var array<string, Money|null> */
    private array $rates = [];

    public function __construct(private readonly SalesStore $store)
    {
    }

    public function rateFor(string $projectId): ?Money
    {
        if (!\array_key_exists($projectId, $this->rates)) {
            $this->rates[$projectId] = $this->read($projectId);
        }

        return $this->rates[$projectId];
    }

    public function reset(): void
    {
        $this->rates = [];
    }

    private function read(string $projectId): ?Money
    {
        $line = $this->store->lineOfProject($projectId);
        if (!$line instanceof DealLine || BillingKind::TimeBased !== $line->billingKind()) {
            return null;
        }
        $deal = $this->store->deal($line->dealId());
        if (!$deal instanceof Deal || $deal->isTrashed() || DealStatus::Lost === $deal->status()) {
            return null;
        }

        return $line->unitPrice();
    }
}
