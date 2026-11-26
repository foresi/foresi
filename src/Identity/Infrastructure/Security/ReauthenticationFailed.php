<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

/**
 * Why the person was not confirmed, worded for them by its reason.
 */
final class ReauthenticationFailed extends \RuntimeException
{
    public const string PASSWORD_WRONG = 'password_wrong';
    public const string CODE_WRONG = 'code_wrong';
    public const string CODE_REUSED = 'code_reused';
    public const string THROTTLED = 'throttled';

    private function __construct(public readonly string $reason, public readonly int $minutes = 0)
    {
        parent::__construct(\sprintf('The person was not confirmed: %s.', $reason));
    }

    public static function passwordWrong(): self
    {
        return new self(self::PASSWORD_WRONG);
    }

    public static function codeWrong(): self
    {
        return new self(self::CODE_WRONG);
    }

    public static function codeReused(): self
    {
        return new self(self::CODE_REUSED);
    }

    public static function throttled(int $minutes): self
    {
        return new self(self::THROTTLED, max(1, $minutes));
    }
}
