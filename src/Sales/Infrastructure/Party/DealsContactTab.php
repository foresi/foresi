<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Party;

use Foresi\Party\Contract\ContactTabs;
use Foresi\Sales\Application\SalesAccess;
use Foresi\Sales\Infrastructure\Controller\ClientDealsController;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Deals tab of a contact, for the owners and members: the deals of the
 * client with the deal each follows from, as HubSpot and Pipedrive list a
 * company's deals on its record. It comes first of the contributed tabs.
 */
final readonly class DealsContactTab implements SlotProvider
{
    public const string KEY = 'deals';
    public const int WEIGHT = 100;

    public function __construct(private SalesAccess $access, private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $contactId = $context['contactId'] ?? null;
        if (ContactTabs::SLOT !== $slot || !\is_string($contactId) || !$this->access->mayWork()) {
            return [];
        }

        return [new SlotItem(data: [
            'key' => self::KEY,
            'label' => $this->translator->trans('sales.client.tab'),
            'href' => $this->urls->generate(ClientDealsController::ROUTE, ['id' => $contactId]),
        ], weight: self::WEIGHT)];
    }
}
