<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Application\Invitations;
use App\Identity\Application\InvitationTenant;
use App\Identity\Domain\Invitation;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineInvitations implements Invitations
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantFilterBypass $bypass,
    ) {
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

    /**
     * The link is opened before any organisation is active, so this one lookup
     * runs with the tenant filter suspended and returns scalars only.
     */
    public function tenantOfToken(string $tokenHash): ?InvitationTenant
    {
        /** @var array{id: string, name: string}|null $row */
        $row = $this->bypass->run(function () use ($tokenHash): ?array {
            /** @var list<array{tenantId: TenantId|string, name: string}> $rows */
            $rows = $this->entityManager
                ->createQuery('SELECT i.tenantId AS tenantId, t.name AS name FROM '.Invitation::class.' i JOIN '.Tenant::class.' t WITH t.id = i.tenantId WHERE i.tokenHash = :hash')
                ->setParameter('hash', $tokenHash)
                ->setMaxResults(1)
                ->getArrayResult();
            if ([] === $rows) {
                return null;
            }

            return ['id' => $rows[0]['tenantId'] instanceof TenantId ? $rows[0]['tenantId']->toString() : $rows[0]['tenantId'], 'name' => $rows[0]['name']];
        });

        return null === $row ? null : new InvitationTenant(TenantId::fromString($row['id']), $row['name']);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
