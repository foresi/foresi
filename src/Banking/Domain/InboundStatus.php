<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * Where an inbound message stands in processing.
 */
enum InboundStatus: string
{
    /** Stored and waiting to be processed. */
    case Received = 'received';
    /** Claimed by a worker right now. */
    case Processing = 'processing';
    /** Its movements were recorded. */
    case Processed = 'processed';
    /** A bank message that moves no money, such as a notice of a payment not yet booked. */
    case Ignored = 'ignored';
    /** No parser knows the message; a later parser may. */
    case NotBankMail = 'notbankmail';
    /** A parser knows the message but could not read it; it is kept for a retry. */
    case Failed = 'failed';
    /** Refused: forged, or about an account that is not the firm's. */
    case Rejected = 'rejected';
    /** A person looked at it and set it aside; the raw message stays stored. */
    case Dismissed = 'dismissed';
}
