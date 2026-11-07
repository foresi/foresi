<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Why a change to the people of the organisation is not made; the reason is the
 * last part of a translation key.
 */
final class PeopleRefused extends \DomainException
{
    public const array REASONS = ['unknown', 'last_owner', 'contact', 'self', 'other_organisations'];

    public function __construct(public readonly string $reason)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(sprintf('The change is refused: %s.', $reason));
    }
}
