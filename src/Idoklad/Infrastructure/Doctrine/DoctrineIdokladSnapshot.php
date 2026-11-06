<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Doctrine;

use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineIdokladSnapshot implements IdokladSnapshot
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function record(LegalEntity $firm, IdokladResource $resource, int $externalId): ?IdokladRecord
    {
        return $this->em->getRepository(IdokladRecord::class)->findOneBy(['firm' => $firm, 'resource' => $resource, 'externalId' => $externalId]);
    }

    public function records(LegalEntity $firm, IdokladResource $resource): iterable
    {
        return $this->em->getRepository(IdokladRecord::class)->findBy(['firm' => $firm, 'resource' => $resource], ['externalId' => 'ASC']);
    }

    public function withoutPdf(LegalEntity $firm, int $limit): array
    {
        $resources = array_values(array_filter(IdokladResource::cases(), static fn (IdokladResource $resource): bool => null !== $resource->pdfReport()));

        return $this->em->getRepository(IdokladRecord::class)->findBy(['firm' => $firm, 'resource' => $resources, 'pdfKey' => null], ['resource' => 'ASC', 'externalId' => 'ASC'], $limit);
    }

    public function lastSucceededPull(LegalEntity $firm): ?IdokladPull
    {
        $pull = $this->em->createQuery('SELECT p FROM '.IdokladPull::class.' p WHERE p.firm = :firm AND p.finishedAt IS NOT NULL AND p.error IS NULL ORDER BY p.startedAt DESC')
            ->setParameter('firm', $firm->id())
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $pull instanceof IdokladPull ? $pull : null;
    }

    public function pulls(LegalEntity $firm): array
    {
        return $this->em->getRepository(IdokladPull::class)->findBy(['firm' => $firm], ['startedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function add(IdokladRecord|IdokladPull $entity): void
    {
        $this->em->persist($entity);
    }
}
