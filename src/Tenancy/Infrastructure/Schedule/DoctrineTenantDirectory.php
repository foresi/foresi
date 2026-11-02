<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\TenantDirectory;
use App\Tenancy\Application\TenantZoneFallback;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Reads the tenants with their stored zones in one query and asks the fallback
 * once for those without, so a tick costs two queries however many tenants
 * there are. A stored zone this PHP no longer knows counts as none, with a
 * warning, so the operator can set it again.
 */
final readonly class DoctrineTenantDirectory implements TenantDirectory
{
    public function __construct(
        private Connection $connection,
        private TenantZoneFallback $fallback,
        private LoggerInterface $logger,
    ) {
    }

    public function ids(): array
    {
        return array_map(
            static fn (mixed $id): TenantId => TenantId::fromString(\is_string($id) ? $id : throw new \LogicException('A tenant id is a string.')),
            $this->connection->fetchFirstColumn('SELECT id FROM tenant ORDER BY id'),
        );
    }

    public function zones(): array
    {
        return $this->resolve($this->connection->fetchAllKeyValue('SELECT id, timezone FROM tenant ORDER BY id'));
    }

    public function zone(TenantId $tenant): \DateTimeZone
    {
        $stored = $this->connection->fetchOne('SELECT timezone FROM tenant WHERE id = :id', ['id' => $tenant->toString()]);

        return $this->resolve([$tenant->toString() => false === $stored ? null : $stored])[$tenant->toString()];
    }

    /**
     * @param array<mixed, mixed> $stored zone names by tenant id
     *
     * @return array<string, \DateTimeZone>
     */
    private function resolve(array $stored): array
    {
        $zones = [];
        $missing = [];
        foreach ($stored as $id => $name) {
            $id = (string) $id;
            $zone = $this->named($id, $name);
            $zones[$id] = $zone;
            if (!$zone instanceof \DateTimeZone) {
                $missing[] = TenantId::fromString($id);
            }
        }

        $fallback = [] === $missing ? [] : $this->fallback->zonesFor($missing);
        $utc = new \DateTimeZone('UTC');
        $resolved = [];
        foreach ($zones as $id => $zone) {
            $resolved[$id] = $zone ?? $fallback[$id] ?? $utc;
        }

        return $resolved;
    }

    private function named(string $tenant, mixed $name): ?\DateTimeZone
    {
        if (!\is_string($name) || '' === $name) {
            return null;
        }
        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            $this->logger->warning('The stored time zone {zone} of tenant {tenant} is unknown to this PHP; its days follow its firm\'s country until the zone is set again.', ['zone' => $name, 'tenant' => $tenant]);

            return null;
        }
    }
}
