<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\MovementTrust;

/**
 * How far the source of a movement is trusted, and the one fact the decision rests
 * on: for a bank mail, its authentication results; for a statement or a bank API,
 * how it came (MovementProvenance).
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
    /** A connector reached the bank's own API with the firm's credentials. */
    public const string BANK_API = MovementProvenance::BANK_API;
    /** A staff member uploaded the statement. */
    public const string UPLOADED = MovementProvenance::UPLOADED;
    /** A module that cannot vouch for its source. */
    public const string UNVERIFIED_SOURCE = MovementProvenance::UNVERIFIED_SOURCE;

    private const array BASES = [
        self::DMARC, self::DKIM, self::ARC, self::NO_RESULTS, self::UNSIGNED, self::AMBIGUOUS, self::PLANTED,
        self::SENDER, self::DKIM_FAIL, self::DMARC_FAIL, self::ACCEPTED_BY_USER,
        self::BANK_API, self::UPLOADED, self::UNVERIFIED_SOURCE,
    ];

    /**
     * @param string $basis one of the constants
     */
    public function __construct(public MovementTrust $trust, public string $basis)
    {
        if (!\in_array($basis, self::BASES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a basis of a trust verdict.', $basis));
        }
    }

    /**
     * The verdict a provenance carries.
     *
     * @throws \InvalidArgumentException for a basis Banking does not know
     */
    public static function of(MovementProvenance $provenance): self
    {
        return new self($provenance->trust, $provenance->basis);
    }
}
