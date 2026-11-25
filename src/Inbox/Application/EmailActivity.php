<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Shared\Contract\Refused;

/**
 * The one e-mail activity of an inbox item (spec 2d, calls P8 and P14): made on
 * the item's first output, never on receive, and extended by every later one.
 *
 * It is an activity of the type email, done when the mail arrived and owned by
 * the item's owner. Its subject is the mail's, cut to 200 characters; its body
 * the sender, the date and the plain text, cut to the 20,000 characters an
 * activity holds with a closing line that points to the item. It is linked to
 * the matched contact, the contact's company and each output whose kind a module
 * offers to activities. The raw mail stays the source.
 */
final readonly class EmailActivity
{
    public const string TYPE = 'email';
    /** The kind Party offers its contacts to activities under. */
    public const string CONTACT = 'contact';
    public const int MAX_SUBJECT = 200;
    public const int MAX_BODY = 20000;
    public const string CUT = "\n\nFull e-mail in the inbox item";

    public function __construct(
        private ActivityRecorder $recorder,
        private LinkableRecords $records,
        private Messages $messages,
        private InstanceClock $clock,
    ) {
    }

    /**
     * Makes the activity, linked to the record too, or adds the record to it.
     * A record of a kind no module offers to activities is left out; an
     * activity that went to the trash since is left as it is.
     *
     * @throws Refused when Activities refuses the new activity
     */
    public function link(InboxItem $item, string $kind, string $recordId): void
    {
        $linkable = $this->records->canLink($kind, $recordId);
        $activityId = $item->emailActivityId();
        if (null !== $activityId) {
            if ($linkable) {
                $this->extend($activityId, $kind, $recordId);
            }

            return;
        }

        $links = [];
        foreach ([$item->contactId(), $item->companyId()] as $contactId) {
            if (null !== $contactId && $this->records->canLink(self::CONTACT, $contactId)) {
                $links[self::CONTACT.':'.$contactId] = ['kind' => self::CONTACT, 'id' => $contactId];
            }
        }
        if ($linkable) {
            $links[$kind.':'.$recordId] = ['kind' => $kind, 'id' => $recordId];
        }
        $message = $this->messages->find($item->mailMessageId());
        $item->recordEmailActivity($this->recorder->record(new RecordedActivity(
            self::TYPE,
            self::subject($message?->subject, $message?->from),
            $this->body($item, $message),
            $item->receivedAt(),
            $item->ownerUserId(),
            array_values($links),
        )));
    }

    /**
     * The mail's subject on one line, cut to what an activity holds; the sender
     * when the mail has none.
     */
    public static function subject(?string $subject, ?string $sender): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) $subject));
        if ('' === $line) {
            $line = trim((string) $sender);
        }

        return '' === $line ? 'E-mail' : rtrim(mb_substr($line, 0, self::MAX_SUBJECT));
    }

    /**
     * "From: <sender>\nDate: <received>\n\n" and the text, at most MAX_BODY
     * characters in all; a longer one is cut and ends with the CUT line.
     */
    public static function cut(string $sender, string $received, string $text): string
    {
        $body = 'From: '.$sender."\nDate: ".$received."\n\n".str_replace(["\r\n", "\r"], "\n", $text);
        if (mb_strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_BODY - mb_strlen(self::CUT)).self::CUT;
    }

    private function body(InboxItem $item, ?MailMessageView $message): string
    {
        return self::cut(
            (string) $message?->from,
            $item->receivedAt()->setTimezone($this->clock->timezone())->format('Y-m-d H:i'),
            $message instanceof MailMessageView ? (string) $this->messages->text($message->id) : '',
        );
    }

    /**
     * @throws Refused when Activities refuses the link for another reason than the trash
     */
    private function extend(string $activityId, string $kind, string $recordId): void
    {
        try {
            $this->recorder->link($activityId, $kind, $recordId);
        } catch (Refused $refused) {
            // The person put the e-mail activity in the trash, or it is gone: the output is still kept.
            if (!\in_array($refused->reason(), ['trashed', 'not_found'], true)) {
                throw $refused;
            }
        }
    }
}
