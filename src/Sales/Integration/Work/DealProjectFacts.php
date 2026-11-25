<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Work;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Sales\Application\SalesAccess;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Sales\Infrastructure\Controller\DealController;
use Foresi\Sales\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Work\Contract\ProjectSlots;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Deal: DUD · DUD development · 1 200,00 Kč/h" in the header of a project an
 * hourly deal line links to, the deal linked to its page. It is shown while
 * the line gives the project's time its rate (DealLineRates): the deal is open
 * or won, not in the trash; and only to the owners and members, who work with
 * deals.
 */
final readonly class DealProjectFacts implements SlotProvider
{
    public const string TEMPLATE = '@Sales/projects/_deal_fact.html.twig';

    /** Next to the client, before the budget. */
    private const int WEIGHT = 10;

    public function __construct(
        private SalesStore $store,
        private SalesAccess $access,
        private DealScreen $screen,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function items(string $slot, array $context): iterable
    {
        $projectId = $context['projectId'] ?? null;
        if (ProjectSlots::HEADER_FACTS !== $slot || !\is_string($projectId) || !$this->access->mayWork()) {
            return [];
        }
        $line = $this->store->lineOfProject($projectId);
        if (!$line instanceof DealLine || BillingKind::TimeBased !== $line->billingKind()) {
            return [];
        }
        $deal = $this->store->deal($line->dealId());
        if (!$deal instanceof Deal || $deal->isTrashed() || DealStatus::Lost === $deal->status()) {
            return [];
        }
        $price = $line->unitPrice();

        return [new SlotItem(self::TEMPLATE, [
            'deal' => $deal->title(),
            'href' => $this->urls->generate(DealController::DEAL_ROUTE, ['id' => $deal->id()]),
            'line' => $line->name(),
            'rate' => $price instanceof Money ? $this->screen->money($price) : null,
        ], self::WEIGHT)];
    }
}
