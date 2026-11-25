<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Mail\Contract\InboundPurpose;

/**
 * The mail of a person's inbox address: each person creates their own address
 * (one at a time) with Mail's address operations, sees it once, and every mail
 * Mail stores for it becomes an inbox item of theirs, in the transaction that
 * stored it. Here and not in Integration/Mail, as Inbox requires Mail and the
 * kernel loads Integration/<module> only for an optional module (as Banking's
 * BankFeedPurpose).
 */
final readonly class InboxPurpose implements InboundPurpose
{
    public const string KEY = 'inbox';
    public const string LABEL = 'Inbox';

    public function __construct(private ReceiveItem $receive)
    {
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
        return true;
    }

    public function reshowable(): bool
    {
        return false;
    }

    public function accept(string $messageId, string $addressId): void
    {
        $this->receive->receive($messageId);
    }
}
