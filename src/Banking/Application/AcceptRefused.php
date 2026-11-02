<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * A message cannot be recorded anyway: only one rejected because its sender
 * could not be trusted can. Nothing was stored.
 */
final class AcceptRefused extends \DomainException
{
    /** Rejected because the account it names is not the firm's: recording it would put money on someone else's books. */
    public const string ACCOUNT_MISMATCH = 'account_mismatch';
    /** Not rejected, or not any more. */
    public const string NOT_REJECTED = 'not_rejected';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The message cannot be recorded anyway: %s.', $reason));
    }
}
