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
use App\Identity\Domain\User;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMembershipDirectory implements MembershipDirectory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantFilterBypass $bypass,
    ) {
    }

    /**
     * The one allowed cross-tenant lookup: it has to see the user's memberships
     * in every tenant, so it runs with the tenant filter suspended.
     */
    public function tenantIdsOf(User $user): array
    {
        /** @var list<string> $ids */
        $ids = $this->bypass->run(fn (): array => $this->entityManager
            ->createQuery('SELECT m.tenantId FROM '.Membership::class.' m WHERE m.user = :user ORDER BY m.id')
            ->setParameter('user', $user)
            ->getSingleColumnResult());

        return array_map(TenantId::fromString(...), $ids);
    }

    /**
     * Cross-tenant like the lookup above, so it also runs with the tenant
     * filter suspended. The query returns scalar columns only (the bypass
     * enforces that); the value objects are built after the filter is back.
     */
    public function organisationsOf(int $userId): array
    {
        /** @var list<array{id: string, name: string, role: string}> $rows */
        $rows = $this->bypass->run(function () use ($userId): array {
            /** @var list<array{tenantId: TenantId|string, tenantName: string, role: Role|string}> $result */
            $result = $this->entityManager
                ->createQuery('SELECT m.tenantId AS tenantId, t.name AS tenantName, m.role AS role FROM '.Membership::class.' m JOIN '.Tenant::class.' t WITH t.id = m.tenantId WHERE IDENTITY(m.user) = :user ORDER BY t.name ASC, m.id ASC')
                ->setParameter('user', $userId)
                ->getArrayResult();

            $rows = [];
            foreach ($result as $row) {
                $rows[] = [
                    'id' => $row['tenantId'] instanceof TenantId ? $row['tenantId']->toString() : $row['tenantId'],
                    'name' => $row['tenantName'],
                    'role' => $row['role'] instanceof Role ? $row['role']->value : $row['role'],
                ];
            }

            return $rows;
        });

        return array_map(
            static fn (array $row): Organisation => new Organisation(TenantId::fromString($row['id']), $row['name'], Role::from($row['role'])),
            $rows,
        );
    }
}
