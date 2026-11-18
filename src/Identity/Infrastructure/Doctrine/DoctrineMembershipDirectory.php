<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\Organisation;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Application\InstanceFirm;

/**
 * The organisation is named after the firm of the instance (ADR 0039); before
 * the first-run setup, after the product.
 */
final readonly class DoctrineMembershipDirectory implements MembershipDirectory
{
    private const string PRODUCT_NAME = 'Foresi';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private InstanceFirm $firm,
    ) {
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

        return new Organisation($this->firm->find()?->name() ?? self::PRODUCT_NAME, $role);
    }
}
