<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Events;

use Foresi\Shared\Application\Event\EventReadAccess;
use Foresi\TaxesAndContributions\Application\TaxesAccess;

/**
 * The events of the taxes and contributions tell about the owner profile, so
 * only the owner reads them (B7). They carry no values anyway.
 */
final readonly class TaxesEventAccess implements EventReadAccess
{
    public function __construct(private TaxesAccess $access)
    {
    }

    public function module(): string
    {
        return 'taxes-and-contributions';
    }

    public function mayRead(): bool
    {
        return $this->access->isOwner();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->access->isOwnerMember($userId);
    }
}
