<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundMessage;

/**
 * The inbound messages of the active tenant.
 */
interface InboundMessages
{
    /**
     * Whether a message with this dedup key is stored already.
     */
    public function has(string $dedupKey): bool;

    /**
     * Stores a new message by a single statement, in the active tenant. Of two
     * messages with one dedup key only the first is stored, even at the same
     * moment; the message object is not managed afterwards.
     *
     * @return bool false when a message with its dedup key is stored already
     */
    public function add(InboundMessage $message): bool;
}
