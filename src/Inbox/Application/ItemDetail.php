<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\SenderCheck;

/**
 * One item of the person's inbox with its mail, its thread and what came of it
 * (ItemReads::get). The mail's sender, subject, text and attachment names are
 * the sender's own words.
 */
final readonly class ItemDetail
{
    public const string SENDER_NOT_VERIFIED = 'sender_not_verified';
    public const string FORWARDED_NOT_VERIFIED = 'forwarded_not_verified';
    public const string DUPLICATE = 'duplicate';

    /**
     * @param MailMessageView|null $message     null once Mail removed the mail
     * @param list<MailAttachment> $attachments
     * @param list<InboxItem>      $thread      the owner's other items of the conversation, oldest first
     * @param list<OutputView>     $outputs     the oldest first
     */
    public function __construct(
        public InboxItem $item,
        public ?MailMessageView $message,
        public ?string $text,
        public array $attachments,
        public array $thread,
        public array $outputs,
    ) {
    }

    /**
     * What the person should know before trusting the mail: the sender not
     * verified by the receiving server, a forward whose original sender nobody
     * verified, the same mail received under another purpose before.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        if ($this->message instanceof MailMessageView && SenderCheck::VERIFIED !== $this->message->verdict) {
            $warnings[] = self::SENDER_NOT_VERIFIED;
        }
        if ($this->item->isForwarded()) {
            $warnings[] = self::FORWARDED_NOT_VERIFIED;
        }
        if (null !== $this->item->duplicateOfPurpose()) {
            $warnings[] = self::DUPLICATE;
        }

        return $warnings;
    }
}
