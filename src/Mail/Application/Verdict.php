<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

/**
 * What the receiving server's results say about a message's sender, and the one
 * result the decision rests on.
 */
final readonly class Verdict
{
    public const string VERIFIED = 'verified';
    public const string FAILED = 'failed';
    public const string UNKNOWN = 'unknown';

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
    /** The sender is not one of the expected domains, or an aligned signature failed. */
    public const string SENDER = 'sender';
    public const string DKIM_FAIL = 'dkim-fail';
    public const string DMARC_FAIL = 'dmarc-fail';

    private const array RESULTS = [self::VERIFIED, self::FAILED, self::UNKNOWN];

    private const array BASES = [
        self::DMARC, self::DKIM, self::ARC, self::NO_RESULTS, self::UNSIGNED, self::AMBIGUOUS, self::PLANTED,
        self::SENDER, self::DKIM_FAIL, self::DMARC_FAIL,
    ];

    /**
     * @param string $result one of VERIFIED, FAILED and UNKNOWN
     * @param string $basis  one of the basis constants
     */
    public function __construct(public string $result, public string $basis)
    {
        if (!\in_array($result, self::RESULTS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a result of a sender verdict.', $result));
        }
        if (!\in_array($basis, self::BASES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a basis of a sender verdict.', $basis));
        }
    }
}
