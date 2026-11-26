<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

/**
 * Marks a page that changes how the person signs in or what can act as them:
 * it opens only within Reauthentication::WINDOW of their last confirmation
 * (GitHub sudo mode). The reason names why they are asked, in their words.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class RequiresReauthentication
{
    public const string TWO_FACTOR = 'two_factor';
    public const string API_TOKEN = 'api_token';
    public const string EMAIL = 'email';
    public const array REASONS = [self::TWO_FACTOR, self::API_TOKEN, self::EMAIL];

    public function __construct(public string $reason)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown reason "%s" to confirm it is the person.', $reason));
        }
    }
}
