<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower(trim($email))]);
    }

    public function findById(string $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function findByPendingEmailToken(string $tokenHash): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['pendingEmailTokenHash' => $tokenHash]);
    }

    public function namesOf(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<User> $users */
        $users = $this->entityManager->createQuery('SELECT u FROM '.User::class.' u WHERE u.id IN (:ids)')->setParameter('ids', $ids)->getResult();
        $names = [];
        foreach ($users as $user) {
            $names[$user->id()] = $user->displayName();
        }

        return $names;
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
