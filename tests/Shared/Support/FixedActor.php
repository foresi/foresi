<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Support;

use Foresi\Shared\Application\ActorRole;

/**
 * An actor of a test: a member of the staff, a guest, or nobody signed in.
 */
final readonly class FixedActor implements ActorRole
{
    private function __construct(private ?string $userId, private bool $staff)
    {
    }

    public static function staff(): self
    {
        return new self('0192f1a4-0000-7000-8000-0000000000a1', true);
    }

    public static function guest(): self
    {
        return new self('0192f1a4-0000-7000-8000-0000000000a2', false);
    }

    /** The application's own work, such as a console command. */
    public static function nobody(): self
    {
        return new self(null, false);
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function isOwner(): bool
    {
        return false;
    }

    public function isStaff(): bool
    {
        return $this->staff;
    }

    public function isStaffMember(string $userId): bool
    {
        return $this->staff;
    }
}
