<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\TenantDirectory;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;

final readonly class DoctrineTenantDirectory implements TenantDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function ids(): array
    {
        return array_map(
            static fn (mixed $id): TenantId => TenantId::fromString(\is_string($id) ? $id : throw new \LogicException('A tenant id is a string.')),
            $this->connection->fetchFirstColumn('SELECT id FROM tenant ORDER BY id'),
        );
    }
}
