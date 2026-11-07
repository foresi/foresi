<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePeople implements People
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function all(): array
    {
        /** @var list<Membership> $memberships */
        $memberships = $this->entityManager->createQuery('SELECT m, u, p FROM '.Membership::class.' m JOIN m.user u LEFT JOIN m.party p ORDER BY u.displayName ASC, m.id ASC')->getResult();
        $people = array_map(static fn (Membership $membership): Person => new Person(
            $membership->id(),
            $membership->user()->id(),
            $membership->user()->displayName(),
            $membership->user()->email(),
            $membership->role(),
            $membership->party()?->id(),
            $membership->party()?->displayName(),
            $membership->user()->hasTwoFactor(),
            $membership->isActive(),
        ), $memberships);
        // Active people first; the query already sorted by name.
        usort($people, static fn (Person $a, Person $b): int => $b->active <=> $a->active);

        return $people;
    }

    public function membership(string $id): ?Membership
    {
        return $this->entityManager->getRepository(Membership::class)->findOneBy(['id' => $id]);
    }

    public function membershipOf(string $userId): ?Membership
    {
        return $this->entityManager->getRepository(Membership::class)->findOneBy(['user' => $userId]);
    }

    public function activeOwners(): int
    {
        return (int) $this->entityManager->createQuery('SELECT COUNT(m.id) FROM '.Membership::class.' m WHERE m.role = :role AND m.disabledAt IS NULL')
            ->setParameter('role', Role::Owner)
            ->getSingleScalarResult();
    }

    public function add(Membership $membership): void
    {
        $this->entityManager->persist($membership);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
