<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\SendStatus;

/**
 * What the invoice list says about the sending of an invoice: the state of its
 * newest send, except that once the customer opened any link the invoice stays
 * read, as a later failed resend does not unread it. A cancelled send never
 * counts: it was stopped with the invoice's trash.
 */
enum SendMark: string
{
    case None = 'none';
    case Queued = 'queued';
    case Sent = 'sent';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * @param SendStatus|null $newest the status of the newest send that was not cancelled
     * @param bool            $read   whether any send of the invoice was opened
     */
    public static function of(?SendStatus $newest, bool $read): self
    {
        if ($read) {
            return self::Read;
        }

        return match ($newest) {
            SendStatus::Queued => self::Queued,
            SendStatus::Sent => self::Sent,
            SendStatus::Failed => self::Failed,
            SendStatus::Cancelled, null => self::None,
        };
    }
}
