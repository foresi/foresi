<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Activities;

use Foresi\Activities\Contract\RecordActivities;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Activities tab of a contact: its timeline, with "Log activity" and
 * "Plan activity" that open their dialogs with the contact linked and come
 * back to this tab (RecordActivities).
 */
final readonly class ActivitiesContactTab implements SlotProvider
{
    /** Before the documents at 110: what happened with a client comes first. */
    private const int WEIGHT = 105;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $contactId = $context['contactId'] ?? null;
        if (ContactTabs::SLOT !== $slot || !\is_string($contactId)) {
            return [];
        }

        return [new SlotItem(data: [
            'key' => 'activities',
            'label' => $this->translator->trans('party.tab.activities'),
            'href' => $this->urls->generate(RecordActivities::ROUTE, [
                'kind' => ContactActivityTarget::KIND,
                'id' => $contactId,
                'return' => $this->urls->generate('app_contact_show', ['id' => $contactId, 'tab' => 'activities']),
            ]),
        ], weight: self::WEIGHT)];
    }
}
