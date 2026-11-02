<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Which part of the bank screen to show: the incoming or outgoing movements, the
 * incoming ones waiting for a pairing, or the bank mail that came; of one firm or
 * account or all, newest first. The text narrows by partner, symbol, message or
 * amount; one movement can be asked for by its id, as a payment links to it.
 */
final readonly class MovementFilter
{
    public const int PER_PAGE = 25;
    public const string INCOMING = 'incoming';
    public const string OUTGOING = 'outgoing';
    public const string UNMATCHED = 'unmatched';
    public const string MESSAGES = 'messages';
    public const array TABS = [self::INCOMING, self::OUTGOING, self::UNMATCHED, self::MESSAGES];

    public function __construct(
        public string $tab = self::INCOMING,
        public ?string $firmId = null,
        public ?string $accountId = null,
        public string $text = '',
        public int $page = 1,
        public ?string $movementId = null,
    ) {
        if (!\in_array($tab, self::TABS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown tab "%s".', $tab));
        }
    }
}
