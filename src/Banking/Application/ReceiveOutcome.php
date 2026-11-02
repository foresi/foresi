<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * What became of one inbound mail.
 */
enum ReceiveOutcome
{
    /** Stored in its tenant and queued for processing. */
    case Stored;
    /** The same mail was stored before; nothing new was kept. */
    case Duplicate;
    /** No recipient names an inbound address of this server; nothing was kept. */
    case UnknownRecipient;
    /** Larger than any bank notification; nothing was kept. */
    case TooLarge;
    /** Not a mail message; nothing was kept. */
    case Unreadable;
}
