<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\Organisation;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Instance\Domain\Tenant;
use App\Instance\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMembershipDirectory implements MembershipDirectory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Deactivated memberships are left out: they no longer let the person in.
     */
    public function roleOf(string $userId): ?Role
    {
        /** @var list<Role|string> $roles */
        $roles = $this->entityManager
            ->createQuery('SELECT m.role FROM '.Membership::class.' m WHERE IDENTITY(m.user) = :user AND m.disabledAt IS NULL')
            ->setParameter('user', $userId)
            ->getSingleColumnResult();

        return match (true) {
            [] === $roles => null,
            $roles[0] instanceof Role => $roles[0],
            default => Role::from($roles[0]),
        };
    }

    public function organisationsOf(string $userId): array
    {
        $role = $this->roleOf($userId);
        if (!$role instanceof Role) {
            return [];
        }
        /** @var list<array{id: TenantId|string, name: string}> $tenants */
        $tenants = $this->entityManager
            ->createQuery('SELECT t.id AS id, t.name AS name FROM '.Tenant::class.' t ORDER BY t.id ASC')
            ->setMaxResults(1)
            ->getArrayResult();

        return array_map(
            static fn (array $tenant): Organisation => new Organisation(
                $tenant['id'] instanceof TenantId ? $tenant['id'] : TenantId::fromString($tenant['id']),
                $tenant['name'],
                $role,
            ),
            $tenants,
        );
    }
}
