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
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The organisation is named after the first active firm, the oldest by its
 * time-ordered id; before the first firm, after the product.
 */
final readonly class DoctrineMembershipDirectory implements MembershipDirectory
{
    private const string PRODUCT_NAME = 'Foresi';

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

    public function organisationOf(string $userId): ?Organisation
    {
        $role = $this->roleOf($userId);
        if (!$role instanceof Role) {
            return null;
        }
        $name = $this->entityManager
            ->createQuery('SELECT f.name FROM '.LegalEntity::class.' f WHERE f.archivedAt IS NULL ORDER BY f.id ASC')
            ->setMaxResults(1)
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return new Organisation(\is_string($name) ? $name : self::PRODUCT_NAME, $role);
    }
}
