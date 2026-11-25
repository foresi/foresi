<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\Event\MailMessageReceived;
use Foresi\Mail\Contract\InboundMail;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\NotAccepted;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Mail\Domain\MailMessage;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Id;
use Psr\Clock\ClockInterface;

/**
 * Takes one raw mail, from the operator mailbox or the webhook, and keeps it
 * for the address its recipient names, handing it to the address's purpose.
 * The secret token in the recipient address is the only route.
 *
 * The file is written first and the row and the hand-over after it, in one
 * transaction, so a crash leaves at most a file without a row, which is removed
 * when the store fails and is harmless otherwise. The same mail twice to one
 * address, by Message-ID with the same bytes or else by its bytes, is one row: a
 * second fetch after a crash, or one fetch and one post of the same bytes. A
 * copy with a Message-ID stored already but other bytes is a row of its own, so
 * junk that took the ID of a notice never keeps the notice out.
 *
 * Once the purpose has accepted a mail, the thin event mail.message.received is
 * recorded in the same transaction: a mail that is refused, fails or is a duplicate
 * records none.
 *
 * Nothing is kept of a mail for no known address, for an address whose purpose
 * no installed module registered, or one its purpose refuses, or for an address
 * that already took its daily limit of mail (see DailyLimit). Never log the
 * address.
 *
 * Call it outside any open transaction, as the poller and the webhook do: inside
 * one, a refused message's row would stay with the outer transaction while its
 * file is removed.
 */
final readonly class ReceiveMail
{
    /**
     * Notifications are a few kilobytes, but documents arrive as PDF attachments, so
     * the limit is Cloudflare's own 25 MiB for mail; anything above it is refused unread.
     */
    public const int MAX_BYTES = 25 * 1024 * 1024;

    /**
     * The header fields that may name the inbound address, in the order they
     * are searched: where the mail was delivered comes before whom it was
     * written to, as a forward keeps the original To.
     */
    public const array RECIPIENT_HEADERS = ['Delivered-To', 'X-Original-To', 'Envelope-To', 'X-Envelope-To', 'X-Forwarded-To', 'To', 'Cc'];

    /** Anything shaped like an address inside a header value, without its brackets, quotes or separators. */
    private const string ADDRESS = '/[^\s<>,;:"()\[\]]+@[^\s<>,;:"()\[\]]+/';

    public function __construct(
        private MailReader $reader,
        private InboundAddresses $addresses,
        private InboundAddressStore $store,
        private InboundPurposes $purposes,
        private MailMessages $messages,
        private InboundFiles $files,
        private SenderVerdict $verdict,
        private MailTransaction $transaction,
        private ClockInterface $clock,
        private DailyLimit $limit,
        private NotifyOverLimit $overLimit,
        private EventRecorder $events,
    ) {
    }

    /**
     * @param string|null $recipient the recipient the relay of a webhook names, searched before the header fields
     */
    public function receive(string $raw, InboundChannel $channel, ?string $recipient = null): ReceiveOutcome
    {
        if (\strlen($raw) > self::MAX_BYTES) {
            return ReceiveOutcome::TooLarge;
        }
        try {
            $mail = $this->reader->read($raw);
        } catch (\InvalidArgumentException) {
            return ReceiveOutcome::Unreadable;
        }
        $token = $this->token($mail, $recipient);
        $address = null === $token ? null : $this->store->routable(InboundToken::hash($token));
        if (!$address instanceof InboundAddress) {
            return ReceiveOutcome::UnknownRecipient;
        }
        $purpose = $this->purposes->get($address->purpose());
        if (!$purpose instanceof InboundPurpose) {
            // No installed module takes this address's mail: dropped unread.
            return ReceiveOutcome::UnknownRecipient;
        }

        if ($this->limit->reached($address->id())) {
            $this->overLimit->notify($address->id());

            return ReceiveOutcome::OverLimit;
        }

        return $this->keep($mail, $raw, $channel, $address, $purpose);
    }

    private function keep(InboundMail $mail, string $raw, InboundChannel $channel, InboundAddress $address, InboundPurpose $purpose): ReceiveOutcome
    {
        $addressId = $address->id();
        $dedupKey = $this->dedupKey($addressId, $mail, $raw);
        if (null === $dedupKey) {
            return ReceiveOutcome::Duplicate;
        }

        $id = Id::generate();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
        $verdict = $this->verdict->judge($mail, $channel);
        $key = $this->files->store($id, $raw);
        try {
            $message = new MailMessage(
                $id, $address, $channel, $dedupKey, $key, $mail->sha256(), $mail->size(),
                $mail->messageId(), $this->inReplyTo($mail), $this->references($mail), $mail->from(), $mail->subject(), $mail->date(),
                $now, $verdict->result, $verdict->basis,
            );
            $stored = $this->transaction->run(function () use ($message, $purpose, $id, $address, $addressId): bool {
                if (!$this->messages->add($message)) {
                    return false;
                }
                $purpose->accept($id, $addressId);
                $this->events->record(new MailMessageReceived($id, $address->purpose()));

                return true;
            });
        } catch (NotAccepted) {
            $this->files->remove($id);

            return ReceiveOutcome::UnknownRecipient;
        } catch (\Throwable $failure) {
            $this->files->remove($id);

            throw $failure;
        }
        if (!$stored) {
            // Another delivery of the same mail was stored a moment before.
            $this->files->remove($id);

            return ReceiveOutcome::Duplicate;
        }
        $this->store->touch($addressId, $now);

        return ReceiveOutcome::Stored;
    }

    /**
     * The key the mail is stored under, or null when it is stored already. The
     * Message-ID is the sender's word, so it only merges copies with the same
     * bytes: a copy with the same Message-ID and other bytes, such as junk that
     * took the ID of a notice the bank is about to send, or the real notice after
     * such junk, is kept under the hash of its bytes, and a movement both carry
     * is a possible duplicate the second time.
     */
    private function dedupKey(string $addressId, InboundMail $mail, string $raw): ?string
    {
        $byBytes = hash('sha256', $raw);
        $messageId = $mail->messageId();
        if (null !== $messageId) {
            $byId = hash('sha256', $messageId);
            $stored = $this->messages->rawHashOf($addressId, $byId);
            if (null === $stored) {
                return $byId;
            }
            if (hash_equals($stored, $mail->sha256())) {
                return null;
            }
        }

        return $this->messages->has($addressId, $byBytes) ? null : $byBytes;
    }

    /**
     * The first Message-ID of In-Reply-To, without its angle brackets.
     */
    private function inReplyTo(InboundMail $mail): ?string
    {
        return $this->ids($mail->headers('In-Reply-To')[0] ?? '')[0] ?? null;
    }

    /**
     * @return list<string> the Message-IDs of References, oldest first
     */
    private function references(InboundMail $mail): array
    {
        return $this->ids($mail->headers('References')[0] ?? '');
    }

    /**
     * @return list<string>
     */
    private function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $found);

        return $found[1];
    }

    /**
     * The token of the first recipient made from the address template.
     */
    private function token(InboundMail $mail, ?string $recipient): ?string
    {
        $values = null === $recipient ? [] : [$recipient];
        foreach (self::RECIPIENT_HEADERS as $name) {
            array_push($values, ...$mail->headers($name));
        }
        foreach ($values as $value) {
            preg_match_all(self::ADDRESS, $value, $found);
            foreach ($found[0] as $address) {
                $token = $this->addresses->tokenIn($address);
                if (null !== $token) {
                    return $token;
                }
            }
        }

        return null;
    }
}
