<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * Why a new password was not set, with the field the person corrects.
 */
final class PasswordRefused extends \DomainException implements Refused
{
    public const string CURRENT_WRONG = 'current_wrong';
    public const string TOO_SHORT = 'too_short';
    public const string TOO_LONG = 'too_long';
    public const string COMPROMISED = 'compromised';

    public const string CURRENT_FIELD = 'currentPassword';
    public const string NEW_FIELD = 'newPassword';

    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function currentWrong(): self
    {
        return new self(self::CURRENT_WRONG, RefusalKind::Invalid, self::CURRENT_FIELD, 'The current password is not right.');
    }

    public static function newPassword(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, self::NEW_FIELD, $message);
    }

    public static function unknownUser(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'The person does not exist.');
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return [];
    }
}
