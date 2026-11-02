<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * How an inbound message reached the server.
 */
enum InboundChannel: string
{
    /** Fetched from the operator mailbox. */
    case Imap = 'imap';
    /** Posted to the inbound mail webhook by a relay. */
    case Webhook = 'webhook';
}
