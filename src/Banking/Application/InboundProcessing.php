<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * Port: queues a stored inbound message for processing. Called inside the
 * transaction that stores it, so a queue kept in the database takes the message
 * with the row or not at all.
 */
interface InboundProcessing
{
    public function queue(string $messageId): void;
}
