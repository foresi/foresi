<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Infrastructure\Doctrine;

use App\Attachment\Application\Attachments;
use App\Attachment\Domain\Attachment;
use App\Shared\Domain\Id;
use Doctrine\ORM\EntityManagerInterface;

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

    public function add(Attachment $attachment): void
    {
        $this->entityManager->persist($attachment);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
