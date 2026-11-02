<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Imap;

use App\Banking\Application\InboundMailbox;

/**
 * No operator mailbox is set (BANK_INBOX_HOST is empty): polling finds nothing,
 * and mail can still arrive by the webhook.
 */
final readonly class DisabledInboundMailbox implements InboundMailbox
{
    public function fetch(int $limit): array
    {
        return [];
    }

    public function markDone(string $uid): void
    {
        $this->none();
    }

    public function markUnknown(string $uid): void
    {
        $this->none();
    }

    public function markRejected(string $uid): void
    {
        $this->none();
    }

    private function none(): never
    {
        throw new \LogicException('No operator mailbox is set, so no message can be moved.');
    }
}
