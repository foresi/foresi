<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundChannel;
use App\Banking\Domain\InboundMessage;
use App\Party\Application\TransactionalWork;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Psr\Clock\ClockInterface;

/**
 * Takes one raw mail, from the operator mailbox or the webhook, and keeps it in
 * the tenant its recipient names. The secret token in the recipient address is
 * the only route: it is looked up across tenants, then that tenant is made
 * active for storing alone, and whatever was active before is active again
 * afterwards (switching discards the entities loaded under it).
 *
 * The file is written first and the row and the processing message after it,
 * in one transaction, so a crash leaves at most a file without a row, which is
 * removed when the store fails and is harmless otherwise. The same mail twice,
 * by Message-ID with the same bytes or else by its bytes, is one row: a second
 * fetch after a crash, or one fetch and one post of the same bytes. A copy with
 * a Message-ID stored already but other bytes is a row of its own, so junk that
 * took the ID of a bank notice never keeps the notice out.
 *
 * Nothing is kept of a mail for no known address. Never log the address.
 */
final readonly class ReceiveInboundMail
{
    /** Bank notifications are a few kilobytes; anything above this is refused unread. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

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
        private BankFeedDirectory $directory,
        private TenantContext $context,
        private InboundMessages $messages,
        private InboundFiles $files,
        private InboundProcessing $processing,
        private BankFeeds $feeds,
        private TransactionalWork $transaction,
        private ClockInterface $clock,
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
        $route = null === $token ? null : $this->directory->route(InboundToken::hash($token));
        if (!$route instanceof FeedRoute) {
            return ReceiveOutcome::UnknownRecipient;
        }

        $previous = $this->context->has() ? $this->context->current() : null;
        $this->context->switchTo(TenantId::fromString($route->tenantId));
        try {
            return $this->store($mail, $raw, $channel, $route);
        } finally {
            if ($previous instanceof TenantId) {
                $this->context->switchTo($previous);
            } else {
                $this->context->reset();
            }
        }
    }

    private function store(InboundMail $mail, string $raw, InboundChannel $channel, FeedRoute $route): ReceiveOutcome
    {
        $dedupKey = $this->dedupKey($mail, $raw);
        if (null === $dedupKey) {
            return ReceiveOutcome::Duplicate;
        }

        $id = Id::generate();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $key = $this->files->store($route->tenantId, $id, $raw);
        try {
            $stored = $this->transaction->run(function () use ($id, $route, $channel, $dedupKey, $key, $mail, $now): bool {
                $message = new InboundMessage($id, $route->feedId, $channel, $dedupKey, $key, $mail->sha256(), $mail->size(), $mail->from(), $mail->subject(), $now);
                if (!$this->messages->add($message)) {
                    return false;
                }
                $this->processing->queue($id);

                return true;
            });
        } catch (\Throwable $failure) {
            $this->files->remove($route->tenantId, $id);

            throw $failure;
        }
        if (!$stored) {
            // Another delivery of the same mail was stored a moment before.
            $this->files->remove($route->tenantId, $id);

            return ReceiveOutcome::Duplicate;
        }
        $this->feeds->touch($route->feedId, $now);

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
    private function dedupKey(InboundMail $mail, string $raw): ?string
    {
        $byBytes = hash('sha256', $raw);
        $messageId = $mail->messageId();
        if (null !== $messageId) {
            $byId = hash('sha256', $messageId);
            $stored = $this->messages->rawHashOf($byId);
            if (null === $stored) {
                return $byId;
            }
            if (hash_equals($stored, $mail->sha256())) {
                return null;
            }
        }

        return $this->messages->has($byBytes) ? null : $byBytes;
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
