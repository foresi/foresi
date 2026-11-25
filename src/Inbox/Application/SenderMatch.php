<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\MatchBasis;

/**
 * The contact a sender points to, and why; none when the address book does not
 * say for certain.
 */
final readonly class SenderMatch
{
    private function __construct(public ?string $contactId, public ?string $companyId, public ?MatchBasis $basis)
    {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function byAddress(string $contactId, ?string $companyId): self
    {
        return new self($contactId, $companyId, MatchBasis::Address);
    }

    public static function byDomain(string $companyId): self
    {
        return new self($companyId, null, MatchBasis::Domain);
    }

    public function matched(): bool
    {
        return null !== $this->contactId;
    }
}
