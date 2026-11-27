<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\DocumentTemplates;

use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Contract\SalesRecords;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Infrastructure\Controller\DealController;
use Foresi\DocumentTemplates\Contract\DocumentLinkTarget;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Documents linked to a deal, such as the NDA or the offer of it. A deal in
 * the trash counts as gone: the document keeps the link and shows it as
 * unavailable until the deal is restored.
 */
final readonly class DealLinkTarget implements DocumentLinkTarget
{
    public function __construct(private DealReads $reads, private UrlGeneratorInterface $urls)
    {
    }

    public function kinds(): array
    {
        return [SalesRecords::KIND];
    }

    public function exists(string $kind, string $id): bool
    {
        return $this->deal($kind, $id) instanceof Deal;
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
            // Not found, or a guest, who sees no deal.
            return null;
        }

        return $deal->isTrashed() ? null : $deal;
    }
}
