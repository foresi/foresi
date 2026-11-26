<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Inbox;

use Foresi\Inbox\Contract\RecordSuggester;
use Foresi\Inbox\Contract\SuggestedRecord;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Domain\DealStatus;

/**
 * The one open deal of the client of an inbox item, when the person sees
 * exactly one (won, lost and trashed deals do not count); none or several
 * propose nothing. The company is the client when the item has one, else the contact.
 */
final readonly class OpenDealSuggester implements RecordSuggester
{
    public function __construct(private DealReads $reads)
    {
    }

    public function kind(): string
    {
        return SalesRecords::KIND;
    }

    public function suggest(string $contactId, ?string $companyId): ?SuggestedRecord
    {
        try {
            $deals = $this->reads->deals(DealStatus::Open->value, clientPartyId: $companyId ?? $contactId);
        } catch (SalesRefused) {
            // A person who sees no deals gets no proposal.
            return null;
        }

        return 1 === \count($deals) ? new SuggestedRecord(SalesRecords::KIND, $deals[0]->id()) : null;
    }
}
