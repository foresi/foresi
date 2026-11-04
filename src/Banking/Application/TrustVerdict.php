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
    public const string ARC = 'arc';
    /** A DKIM pass alone no longer verifies; the basis stays readable for verdicts given before. */
    public const string DKIM = 'dkim';
    /** No trusted server left results, or the message carries no verifying result. */
    public const string NO_RESULTS = 'no-results';
    public const string UNSIGNED = 'unsigned';
    /** The trusted results contradict themselves: two DMARC results, or a pass and a fail for one signer. */
    public const string AMBIGUOUS = 'ambiguous';
    /** A results header below the topmost carries a trusted id, so someone planted it or the server misplaced it. */
    public const string PLANTED = 'planted-results';
    /** The sender is not the bank's, or an aligned signature failed. */
    public const string SENDER = 'sender';
    public const string DKIM_FAIL = 'dkim-fail';
    public const string DMARC_FAIL = 'dmarc-fail';
    /** Someone recorded a failed message anyway, after the warning. */
    public const string ACCEPTED_BY_USER = 'accepted-by-user';

    private const array BASES = [
        self::DMARC, self::DKIM, self::ARC, self::NO_RESULTS, self::UNSIGNED, self::AMBIGUOUS, self::PLANTED,
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
