<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\MessageTrust;

/**
 * How far a message is trusted, and the one result the decision rests on.
 */
final readonly class TrustVerdict
{
    /** The aligned results that verify a message. */
    public const string DMARC = 'dmarc';
    public const string DKIM = 'dkim';
    public const string ARC = 'arc';
    /** No trusted server left results, or the message carries no signature. */
    public const string NO_RESULTS = 'no-results';
    public const string UNSIGNED = 'unsigned';
    /** The sender is not the bank's, or an aligned signature failed. */
    public const string SENDER = 'sender';
    public const string DKIM_FAIL = 'dkim-fail';
    public const string DMARC_FAIL = 'dmarc-fail';
    /** Someone recorded a failed message anyway, after the warning. */
    public const string ACCEPTED_BY_USER = 'accepted-by-user';

    private const array BASES = [
        self::DMARC, self::DKIM, self::ARC, self::NO_RESULTS, self::UNSIGNED,
        self::SENDER, self::DKIM_FAIL, self::DMARC_FAIL, self::ACCEPTED_BY_USER,
    ];

    /**
     * @param string $basis one of the constants
     */
    public function __construct(public MessageTrust $trust, public string $basis)
    {
        if (!\in_array($basis, self::BASES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a basis of a trust verdict.', $basis));
        }
    }
}
