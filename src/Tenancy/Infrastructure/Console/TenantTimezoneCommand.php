<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Tenancy\Application\Schedule\TenantDirectory;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sets the zone a tenant's days follow, for the operator: a tenant whose first
 * firm's country is not where it works, or one with no firm yet. Clearing it
 * goes back to the firm's country. Only zones of the tz database are taken, as
 * an offset knows no summer time.
 */
#[AsCommand(name: 'app:tenant:timezone', description: 'Set the time zone of a tenant')]
final readonly class TenantTimezoneCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private TenantDirectory $tenants,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The id of the tenant')]
        string $tenant,
        #[Argument('A zone of the tz database, such as Europe/Prague')]
        ?string $zone = null,
        #[Option('Remove the stored zone, so the country of the first active firm applies')]
        bool $clear = false,
    ): int {
        if ($clear === (null !== $zone)) {
            $io->error('Give a zone, or --clear to remove the stored one.');

            return Command::FAILURE;
        }

        $id = $this->id($tenant);
        $found = $id instanceof TenantId ? $this->em->find(Tenant::class, $id) : null;
        if (!$id instanceof TenantId || !$found instanceof Tenant) {
            $io->error(\sprintf('No tenant has the id %s.', $tenant));

            return Command::FAILURE;
        }

        try {
            $found->changeTimezone(null === $zone ? null : new \DateTimeZone($zone));
        } catch (\Exception) {
            $io->error(\sprintf('"%s" is not a time zone of the tz database, such as Europe/Prague.', $zone));

            return Command::FAILURE;
        }
        $this->em->flush();

        $io->success(null === $zone
            ? \sprintf('The tenant %s follows its firm again: its days are now in %s.', $tenant, $this->tenants->zone($id)->getName())
            : \sprintf('The days of tenant %s are now in %s.', $tenant, $zone));

        return Command::SUCCESS;
    }

    private function id(string $tenant): ?TenantId
    {
        try {
            return TenantId::fromString($tenant);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
