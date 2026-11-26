<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * One inbox item with its mail (InboxItems::find). The sender, original sender,
 * subject, text and attachment names are the sender's own words.
 */
final readonly class InboxItemView
{
    /**
     * @param string                $state              new, done or discarded
     * @param string|null           $sender             the From address; null once the mail is removed
     * @param string|null           $originalSender     the sender a forward names, which nobody verified
     * @param string                $verdict            verified, failed or unknown: what the receiving server said of the sender
     * @param string|null           $companyId          the company of a matched person, when the person has exactly one
     * @param string|null           $text               the plain text of the mail; null when it has none or is removed
     * @param list<InboxAttachment> $attachments
     * @param list<string>          $threadItemIds      the owner's other items of the same conversation, oldest first; none for a person who is not the owner
     * @param list<InboxOutput>     $outputs            what came of the item, oldest first
     * @param string|null           $duplicateOfPurpose the purpose the same mail arrived under before, such as bank_feed
     */
    public function __construct(
        public string $id,
        public string $ownerUserId,
        public string $state,
        public \DateTimeImmutable $receivedAt,
        public ?string $sender,
        public ?string $originalSender,
        public string $verdict,
        public bool $forwarded,
        public ?string $contactId,
        public ?string $companyId,
        public string $subject,
        public ?string $text,
        public array $attachments,
        public array $threadItemIds,
        public array $outputs,
        public ?string $emailActivityId,
        public ?string $duplicateOfPurpose,
    ) {
    }
}
