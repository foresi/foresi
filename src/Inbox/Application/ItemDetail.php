<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Contract\InboxAttachment;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxOutput;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\Suggestion;
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
     * @param list<Suggestion>     $suggestions the oldest first; for the owner only, none for anyone else
     */
    public function __construct(
        public InboxItem $item,
        public ?MailMessageView $message,
        public ?string $text,
        public array $attachments,
        public array $thread,
        public array $outputs,
        public array $suggestions = [],
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

    /**
     * The item as other modules read it (InboxItems::find). Another person than
     * the owner learns nothing of the owner's mailbox: not the thread, nor the
     * outputs they do not see.
     */
    public function view(bool $owner): InboxItemView
    {
        $item = $this->item;
        $outputs = $owner ? $this->outputs : array_values(array_filter($this->outputs, static fn (OutputView $output): bool => $output->available));

        return new InboxItemView(
            $item->id(),
            $item->ownerUserId(),
            $item->state()->value,
            $item->receivedAt(),
            $this->message?->from,
            $item->originalFrom(),
            $this->message->verdict ?? SenderCheck::UNKNOWN,
            $item->isForwarded(),
            $item->contactId(),
            $item->companyId(),
            $this->message->subject ?? '',
            $this->text,
            array_map(static fn (MailAttachment $attachment): InboxAttachment => new InboxAttachment($attachment->index, $attachment->name, $attachment->contentType, $attachment->size), $this->attachments),
            $owner ? array_map(static fn (InboxItem $other): string => $other->id(), $this->thread) : [],
            array_map(static fn (OutputView $output): InboxOutput => new InboxOutput($output->output->kind(), $output->output->recordId(), $output->output->addedAt()), $outputs),
            $item->emailActivityId(),
            $item->duplicateOfPurpose(),
        );
    }
}
