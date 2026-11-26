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
 * The one e-mail activity of an inbox item: made on the item's first output,
 * never on receive, and extended by every later one.
 *
 * It is an activity of the type email, done when the mail arrived and owned by
 * the item's owner. Its subject is the mail's, cut to 200 characters; its body
 * the sender, the date and the plain text, escaped so the activity's Markdown
 * shows them literally (no links of the mail), cut to the 20,000 characters an
 * activity holds with a closing line that points to the item. It is linked to
 * the matched contact, the contact's company (also when matched later) and each
 * output whose kind a module offers to activities. The raw mail stays the source.
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
     * Whether an output can be added to the item now: it has its e-mail
     * activity, or Activities takes a new one, which it does not while the type
     * email is archived or missing.
     */
    public function canLink(InboxItem $item): bool
    {
        return null !== $item->emailActivityId() || $this->recorder->accepts(self::TYPE);
    }

    /**
     * Whether a record of the kind can be linked to an item by hand
     * (inbox.items.link): some module offers the kind to activities.
     */
    public function linksByHand(string $kind): bool
    {
        return \in_array($kind, $this->records->kinds(), true);
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
     * Adds the item's matched contact and its company to an activity made
     * before the match; never removes one. Nothing before the first output.
     *
     * @throws Refused when Activities refuses a link for another reason than the trash
     */
    public function linkContacts(InboxItem $item): void
    {
        $activityId = $item->emailActivityId();
        if (null === $activityId) {
            return;
        }
        // An item never names one contact as both its contact and its company (InboxItem::match()).
        foreach (array_filter([$item->contactId(), $item->companyId()], static fn (?string $id): bool => null !== $id) as $contactId) {
            if ($this->records->canLink(self::CONTACT, $contactId)) {
                $this->extend($activityId, self::CONTACT, $contactId);
            }
        }
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
     * characters in all; a longer one is cut and ends with the CUT line. The
     * sender and the text are made literal() first, so the activity shows them
     * as written, and the cut never splits an escape.
     */
    public static function cut(string $sender, string $received, string $text): string
    {
        $body = 'From: '.self::literal($sender)."\nDate: ".$received."\n\n".self::literal(str_replace(["\r\n", "\r"], "\n", $text));
        if (mb_strlen($body) <= self::MAX_BODY) {
            return $body;
        }
        // CUT is ASCII, so its bytes are its characters.
        $kept = mb_substr($body, 0, self::MAX_BODY - \strlen(self::CUT));
        // The cut must not split an escape and leave a lone backslash before the closing line.
        $trailing = strspn(strrev($kept), '\\');
        if (1 === $trailing % 2) {
            $kept = substr($kept, 0, -1);
        }

        return $kept.self::CUT;
    }

    /**
     * The text with every ASCII punctuation character backslash-escaped, so the
     * Markdown of the activity screens shows it as written: no link,
     * heading, list, emphasis or HTML of the mail, and no address or URL made a
     * link, since an escaped "." "@" or ":" breaks what the autolinks look for.
     */
    public static function literal(string $text): string
    {
        return (string) preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $text);
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
