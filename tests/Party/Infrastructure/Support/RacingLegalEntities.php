<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Support;

use App\Party\Application\LegalEntities;
use App\Party\Domain\LegalEntity;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Test double decorating the real LegalEntities port: while switched on, the
 * list is empty, the way the check of the form sees it when another request
 * stores the same registration number a moment later. Test env only.
 */
final class RacingLegalEntities implements LegalEntities
{
    public static bool $blind = false;

    public function __construct(#[AutowireDecorated] private readonly LegalEntities $inner)
    {
    }

    public function all(): array
    {
        return self::$blind ? [] : $this->inner->all();
    }

    public function active(): array
    {
        return $this->inner->active();
    }

    public function get(string $id): LegalEntity
    {
        return $this->inner->get($id);
    }

    public function lockActive(): array
    {
        return $this->inner->lockActive();
    }

    public function add(LegalEntity $firm): void
    {
        $this->inner->add($firm);
    }
}
