<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Inbox;

use Foresi\Deals\Domain\Deal;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxPreFill;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;

/**
 * The deal starts as the mail: its subject as the title, the company of the
 * sender as the client, else the contact. The client is picked from the two by
 * name: those of them that exist and are not archived.
 */
final readonly class CreateDealFromEmailPreFill implements InboxPreFill
{
    public function __construct(private PartyDirectory $parties)
    {
    }

    public function operation(): string
    {
        return 'inbox.actions.create_deal';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        $values = ['title' => mb_substr(trim($item->subject), 0, Deal::MAX_TITLE), 'markDone' => true];
        $clients = [];
        foreach (array_filter([$item->companyId, $item->contactId], is_string(...)) as $id) {
            $party = $this->parties->find($id);
            if ($party instanceof PartySummary && !$party->archived) {
                $clients[$party->id] = $party->displayName;
            }
        }
        if ([] !== $clients) {
            $values['clientPartyId'] = array_key_first($clients);
        }

        return new PreFill($values, [] === $clients ? [] : ['clientPartyId' => $clients]);
    }
}
