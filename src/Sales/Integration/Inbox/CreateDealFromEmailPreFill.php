<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Inbox;

use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxPreFill;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Sales\Domain\Deal;

/**
 * The deal starts as the mail: its subject as the title, the company of the
 * sender as the client, else the contact.
 */
final readonly class CreateDealFromEmailPreFill implements InboxPreFill
{
    public function operation(): string
    {
        return 'inbox.actions.create_deal';
    }

    public function prefill(InboxItemView $item): PreFill
    {
        $values = ['title' => mb_substr(trim($item->subject), 0, Deal::MAX_TITLE), 'markDone' => true];
        $client = $item->companyId ?? $item->contactId;
        if (null !== $client) {
            $values['clientPartyId'] = $client;
        }

        return new PreFill($values);
    }
}
