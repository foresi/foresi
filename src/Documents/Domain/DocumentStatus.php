<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

/**
 * Where a document is in its life. Expired is reserved: nothing sets it yet.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case OutForSignature = 'out_for_signature';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isDraft(): bool
    {
        return self::Draft === $this;
    }

    /** A final document changes no more; generating it again makes a new draft. */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Completed, self::Declined, self::Cancelled, self::Expired], true);
    }
}
