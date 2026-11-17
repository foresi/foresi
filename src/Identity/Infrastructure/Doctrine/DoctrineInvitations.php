<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Invitations;
use App\Identity\Domain\Invitation;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineInvitations implements Invitations
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Invitation $invitation): void
    {
        $this->entityManager->persist($invitation);
    }

    public function find(string $id): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(['id' => $id]);
    }

    public function byTokenHash(string $tokenHash): ?Invitation
    {
        return $this->entityManager->getRepository(Invitation::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function pendingFor(string $email, \DateTimeImmutable $now): array
    {
        /* @var list<Invitation> */
        return $this->entityManager->createQuery('SELECT i FROM '.Invitation::class.' i WHERE i.email = :email AND i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt > :now')
            ->setParameter('email', strtolower(trim($email)))
            ->setParameter('now', $now, 'datetime_immutable')
            ->getResult();
    }

    public function pending(\DateTimeImmutable $now): array
    {
        /* @var list<Invitation> */
        return $this->entityManager->createQuery('SELECT i FROM '.Invitation::class.' i WHERE i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt > :now ORDER BY i.expiresAt ASC, i.id ASC')
            ->setParameter('now', $now, 'datetime_immutable')
            ->getResult();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
