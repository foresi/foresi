<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Inbox\Contract\InboxAttachment;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxOutput;

/**
 * An inbox item as a module reads it, made by hand: the exact subject and text a
 * pre-fill sees, without a mail parser between them.
 */
trait ItemViews
{
    /**
     * @param list<InboxAttachment> $attachments
     * @param list<InboxOutput>     $outputs
     */
    private function itemView(
        string $subject = 'Offer for the new office',
        ?string $text = null,
        ?string $contactId = null,
        ?string $companyId = null,
        array $attachments = [],
        array $outputs = [],
        ?\DateTimeImmutable $receivedAt = null,
    ): InboxItemView {
        return new InboxItemView(
            id: '0192f0a0-0000-7000-8000-000000000001',
            ownerUserId: '0192f0a0-0000-7000-8000-000000000002',
            state: 'new',
            receivedAt: $receivedAt ?? new \DateTimeImmutable('2026-11-24 09:35:00 UTC'),
            sender: 'client.a@client-a.example.com',
            originalSender: null,
            verdict: 'verified',
            forwarded: false,
            contactId: $contactId,
            companyId: $companyId,
            subject: $subject,
            text: $text,
            attachments: $attachments,
            threadItemIds: [],
            outputs: $outputs,
            emailActivityId: null,
            duplicateOfPurpose: null,
        );
    }
}
