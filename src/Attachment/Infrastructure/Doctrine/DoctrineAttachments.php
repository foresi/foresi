<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineAttachments implements Attachments
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(string $ownerKind, string $ownerId): array
    {
        if (!Id::isValid($ownerId)) {
            return [];
        }

        return $this->entityManager->getRepository(Attachment::class)->findBy(
            ['ownerKind' => $ownerKind, 'ownerId' => $ownerId, 'deletedAt' => null],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    public function get(string $id): ?Attachment
    {
        return Id::isValid($id) ? $this->entityManager->getRepository(Attachment::class)->findOneBy(['id' => $id, 'deletedAt' => null]) : null;
    }

    public function deleted(string $id): ?Attachment
    {
        if (!Id::isValid($id)) {
            return null;
        }
        $attachment = $this->entityManager->getRepository(Attachment::class)->find($id);

        return $attachment instanceof Attachment && $attachment->isDeleted() ? $attachment : null;
    }

    public function deletedOf(string $ownerKind, string $ownerId): array
    {
        if (!Id::isValid($ownerId)) {
            return [];
        }

        /* @var list<Attachment> */
        return $this->entityManager
            ->createQuery('SELECT a FROM '.Attachment::class.' a WHERE a.ownerKind = :kind AND a.ownerId = :owner AND a.deletedAt IS NOT NULL ORDER BY a.deletedAt DESC, a.id DESC')
            ->setParameter('kind', $ownerKind)
            ->setParameter('owner', $ownerId)
            ->getResult();
    }

    public function deletedBefore(\DateTimeImmutable $moment): array
    {
        /* @var list<Attachment> */
        return $this->entityManager
            ->createQuery('SELECT a FROM '.Attachment::class.' a WHERE a.deletedAt IS NOT NULL AND a.deletedAt < :moment ORDER BY a.deletedAt ASC, a.id ASC')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->getResult();
    }

    public function allOf(string $ownerKind, array $ownerIds): array
    {
        $ownerIds = array_values(array_filter($ownerIds, Id::isValid(...)));
        if ([] === $ownerIds) {
            return [];
        }

        return $this->entityManager->getRepository(Attachment::class)->findBy(['ownerKind' => $ownerKind, 'ownerId' => $ownerIds], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function remove(Attachment $attachment): void
    {
        $this->entityManager->remove($attachment);
    }

    public function add(Attachment $attachment): void
    {
        $this->entityManager->persist($attachment);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
