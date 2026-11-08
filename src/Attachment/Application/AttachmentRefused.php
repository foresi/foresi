<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

/**
 * Why a file was not attached or removed. The reason names the message
 * (attachment.error.<reason>); "unknown" means the record or the attachment
 * is not there for the person, and is answered as not found.
 */
final class AttachmentRefused extends \DomainException
{
    public const string EMPTY = 'empty';
    public const string TOO_BIG = 'too_big';
    public const string FAILED = 'failed';
    public const string NOT_ALLOWED = 'not_allowed';
    public const string UNKNOWN = 'unknown';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('The attachment was refused: %s.', $reason), 0, $previous);
    }
}
