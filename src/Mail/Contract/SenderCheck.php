<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * What the receiving servers' results say about who sent a message.
 */
final readonly class SenderCheck
{
    public const string VERIFIED = 'verified';
    public const string FAILED = 'failed';
    public const string UNKNOWN = 'unknown';

    /**
     * @param string $verdict verified, failed or unknown
     * @param string $basis   the one result the verdict rests on, such as dmarc, sender or no-results
     */
    public function __construct(public string $verdict, public string $basis)
    {
    }
}
