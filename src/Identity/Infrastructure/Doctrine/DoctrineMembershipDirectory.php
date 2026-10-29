<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Domain\Membership;
use App\Identity\Domain\User;
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
}
