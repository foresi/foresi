<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Activities;

use Foresi\Activities\Contract\ActivityTarget;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Infrastructure\Controller\DealController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Activities about a deal, seen by the owners and members, who see the deals.
 * A deal in the trash counts as gone: the activity keeps the link, and is then
 * seen through its other records, such as the client, or by its owner alone.
 */
final readonly class DealActivityTarget implements ActivityTarget
{
    public function __construct(private DealReads $reads, private UrlGeneratorInterface $urls)
    {
    }

    public function kinds(): array
    {
        return [SalesRecords::KIND];
    }

    public function canSee(string $kind, string $id): bool
    {
        return $this->deal($kind, $id) instanceof Deal;
    }

    public function canLink(string $kind, string $id): bool
    {
        return $this->canSee($kind, $id);
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->deal($kind, $id)?->title();
    }

    public function url(string $kind, string $id): ?string
    {
        $deal = $this->deal($kind, $id);

        return $deal instanceof Deal ? $this->urls->generate(DealController::DEAL_ROUTE, ['id' => $deal->id()]) : null;
    }

    private function deal(string $kind, string $id): ?Deal
    {
        if (SalesRecords::KIND !== $kind || !Uuid::isValid($id)) {
            return null;
        }
        try {
            $deal = $this->reads->deal($id)->deal;
        } catch (SalesRefused) {
            // Not found, or a guest, who sees no deal and so no activity through it.
            return null;
        }

        return $deal->isTrashed() ? null : $deal;
    }
}
