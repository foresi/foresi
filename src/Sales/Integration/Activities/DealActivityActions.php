<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Activities;

use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Contract\SalesSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Log activity" and "Plan activity" in the header of a deal's page: the
 * dialogs of Activities (RecordActivities) with the deal, its client and the
 * deal's contact persons linked, so the call shows on the deal, on the client
 * and on each person, and back to the deal once saved.
 */
final readonly class DealActivityActions implements SlotProvider
{
    public const string LOG = 'log-activity';

    public const string PLAN = 'plan-activity';

    /** The route of the dialogs of Activities (RecordActivities names it). */
    private const string NEW_ROUTE = 'app_activity_new';

    /** The record kind of a contact of the address book, as Party links it. */
    private const string CONTACT = 'contact';

    /** Before "New document" at 20. */
    private const int WEIGHT = 10;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $dealId = $context['dealId'] ?? null;
        $clientId = $context['clientId'] ?? null;
        if (SalesSlots::DEAL_ACTIONS !== $slot || !\is_string($dealId) || !\is_string($clientId)) {
            return [];
        }
        $persons = $context['contactPersonIds'] ?? [];
        $links = [self::CONTACT.':'.$clientId];
        foreach (\is_array($persons) ? $persons : [] as $personId) {
            if (\is_string($personId)) {
                $links[] = self::CONTACT.':'.$personId;
            }
        }
        $return = $context['return'] ?? null;
        $href = function (string $mode) use ($dealId, $links, $return): string {
            $parameters = ['mode' => $mode, 'link_kind' => SalesRecords::KIND, 'link_id' => $dealId, 'links' => $links];
            if (\is_string($return)) {
                $parameters['return'] = $return;
            }

            return $this->urls->generate(self::NEW_ROUTE, $parameters);
        };

        return [
            new SlotItem(data: [
                'key' => self::LOG,
                'label' => $this->translator->trans('sales.deal.log_activity'),
                'icon' => 'square-pen',
                'dialog' => true,
                'href' => $href('log'),
                // What a seller does most on a deal, so it stands beside Won (HubSpot, Pipedrive).
                'prominent' => true,
            ], weight: self::WEIGHT),
            new SlotItem(data: [
                'key' => self::PLAN,
                'label' => $this->translator->trans('sales.deal.plan_activity'),
                'icon' => 'calendar',
                'dialog' => true,
                'href' => $href('plan'),
            ], weight: self::WEIGHT + 1),
        ];
    }
}
