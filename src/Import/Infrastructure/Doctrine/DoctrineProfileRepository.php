<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Import\Application\ProfileRepository;
use App\Import\Domain\ImportProfile;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProfileRepository implements ProfileRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(ImportProfile $profile): void
    {
        $this->em->persist($profile);
        $this->em->flush();
    }

    public function find(int $id): ?ImportProfile
    {
        return $this->em->find(ImportProfile::class, $id);
    }

    public function findByHeaders(string $targetKey, string $headerSignature): ?ImportProfile
    {
        return $this->em->getRepository(ImportProfile::class)->findOneBy(
            ['targetKey' => $targetKey, 'headerSignature' => $headerSignature],
            ['updatedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function forTarget(string $targetKey): array
    {
        return $this->em->getRepository(ImportProfile::class)->findBy(['targetKey' => $targetKey], ['updatedAt' => 'DESC', 'id' => 'DESC']);
    }
}
