<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * The intake took nothing of a statement.
 */
final class StatementRefused extends \RuntimeException
{
    /** The statement is not of an active bank account of the firm. */
    public const string UNKNOWN_ACCOUNT = 'unknown_account';

    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }
}
