<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\LinkAudience;

/**
 * The secret part of a link to a sent document: 43 characters, 256 bits, derived
 * from the installation secret, the send and the audience when the send is
 * queued. Derived rather than stored, so no link sits in a table, a queue or a
 * log; the row keeps only its hash, and a link is looked up by that hash. A link
 * that was delivered keeps working when the secret changes, as ADR 0015 says. A
 * send still queued then fails rather than mail a link that leads nowhere: the
 * worker derives the link again and checks it against the stored hash.
 */
final readonly class LinkTokens
{
    public const string PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public function __construct(private string $secret)
    {
        if ('' === $secret) {
            throw new \LogicException('Links to sent documents need the application secret.');
        }
    }

    public function token(string $sendId, LinkAudience $audience): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'invoice-link|'.$sendId.'|'.$audience->value, $this->secret, true)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return 1 === preg_match(self::PATTERN, $token);
    }
}
