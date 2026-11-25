<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * Where the movements of a statement come from, and how far that source is trusted.
 * The channel that knows the source makes the verdict: a connector that reached the
 * bank's own host over TLS with the firm's credentials, an upload by staff, the
 * authentication results of a mail. The verdict and its basis are stored with every
 * movement and shown, so a module's claim stays visible.
 *
 * A failed source never reaches the intake: its movements are rejected unread.
 */
final readonly class MovementProvenance
{
    /** A connector reached the bank's own API over TLS with the firm's credentials. */
    public const string BANK_API = 'bank-api';
    /** A staff member who may record payments uploaded the statement. */
    public const string UPLOADED = 'uploaded';
    /** A module that cannot vouch for its source. */
    public const string UNVERIFIED_SOURCE = 'unverified-source';

    public const int MAX_SOURCE_ID = 64;
    public const int MAX_BASIS = 32;

    /**
     * @param string      $sourceId         the id of the format, connector or mail parser, the same for every statement of the source
     * @param string      $basis            the one fact the trust rests on
     * @param string|null $inboundMessageId the bank mail the movements came in
     * @param string|null $actorId          the user who uploaded the statement
     */
    private function __construct(
        public MovementChannel $channel,
        public string $sourceId,
        public MovementTrust $trust,
        public string $basis,
        public ?string $inboundMessageId = null,
        public ?string $actorId = null,
    ) {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $sourceId) || \strlen($sourceId) > self::MAX_SOURCE_ID) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a source id: lowercase letters, digits, dots, dashes and underscores, at most %d.', $sourceId, self::MAX_SOURCE_ID));
        }
        if ('' === $basis || \strlen($basis) > self::MAX_BASIS) {
            throw new \InvalidArgumentException('A trust basis is a short, non-empty name.');
        }
        if (MovementTrust::Failed === $trust) {
            throw new \InvalidArgumentException('A failed source is rejected before its movements reach the intake.');
        }
    }

    /**
     * A bank API that a connector module called with the firm's credentials.
     */
    public static function bankApi(string $connectorId): self
    {
        return new self(MovementChannel::Api, $connectorId, MovementTrust::Verified, self::BANK_API);
    }

    /**
     * A statement file a staff member uploaded, read by the format of that id.
     */
    public static function uploaded(string $formatId, string $actorId): self
    {
        return new self(MovementChannel::Statement, $formatId, MovementTrust::Verified, self::UPLOADED, actorId: $actorId);
    }

    /**
     * A source a module cannot vouch for: its movements are recorded and never pay by themselves.
     */
    public static function unverified(MovementChannel $channel, string $sourceId): self
    {
        if (MovementChannel::Mail === $channel) {
            throw new \InvalidArgumentException('Bank mail is judged by Banking itself.');
        }

        return new self($channel, $sourceId, MovementTrust::Unverified, self::UNVERIFIED_SOURCE);
    }

    /**
     * A bank mail, judged by Banking from its authentication results. Only Banking's
     * own mail path makes these.
     *
     * @param string $parserId the BankMessageParser that read it
     */
    public static function mail(string $parserId, MovementTrust $trust, string $basis, string $inboundMessageId): self
    {
        return new self(MovementChannel::Mail, $parserId, $trust, $basis, $inboundMessageId);
    }
}
