<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\InboundMessage;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\NotAccepted;
use Psr\Clock\ClockInterface;

/**
 * The mail of a bank feed's address: Mail hands each stored message over in
 * the transaction that stored it, and it becomes a row of the bank mail, of the
 * same id, queued for processing. The feed of an archived account takes in
 * nothing, as it gets no address either.
 */
final readonly class BankFeedPurpose implements InboundPurpose
{
    public const string KEY = 'bank_feed';
    public const string LABEL = 'Bank feed';

    public function __construct(
        private BankFeedDirectory $directory,
        private InboundMessages $messages,
        private InboundProcessing $processing,
        private BankFeeds $feeds,
        private ClockInterface $clock,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function ownerRequired(): bool
    {
        return false;
    }

    public function reshowable(): bool
    {
        return true;
    }

    public function accept(string $messageId, string $addressId): void
    {
        $route = $this->directory->route($addressId) ?? throw new NotAccepted('No active bank feed has this address.');
        $this->messages->add(new InboundMessage($messageId, $route->feedId, $messageId));
        $this->processing->queue($messageId);
        $this->feeds->touch($route->feedId, \DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
