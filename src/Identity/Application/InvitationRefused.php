<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Why an invitation cannot be made, sent or accepted; the reason is the last
 * part of a translation key.
 */
final class InvitationRefused extends \DomainException
{
    public const array REASONS = ['email', 'name', 'role', 'contact', 'member', 'unknown', 'not_pending', 'user_exists', 'other_user'];

    public function __construct(public readonly string $reason)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(sprintf('The invitation is refused: %s.', $reason));
    }
}
