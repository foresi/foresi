<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Activities;

use Foresi\Activities\Contract\RecordActivities;
use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Contract\SalesSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Activities section of a deal's page: the deal's timeline from
 * RecordActivities, loaded by a lazy frame, whose dialogs come back to the
 * deal, as HubSpot and Pipedrive show the activities on a deal.
 */
final readonly class DealActivitiesSection implements SlotProvider
{
    public const string KEY = 'activities';

    /** Before the documents at 20: what happened with the deal comes first. */
    private const int WEIGHT = 10;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $dealId = $context['dealId'] ?? null;
        $return = $context['return'] ?? null;
        if (SalesSlots::DEAL_SECTIONS !== $slot || !\is_string($dealId)) {
            return [];
        }
        $parameters = ['kind' => SalesRecords::KIND, 'id' => $dealId];
        if (\is_string($return)) {
            $parameters['return'] = $return;
        }

        return [new SlotItem(data: [
            'key' => self::KEY,
            'label' => $this->translator->trans('sales.deal.tab.activities'),
            'href' => $this->urls->generate(RecordActivities::ROUTE, $parameters),
        ], weight: self::WEIGHT)];
    }
}
