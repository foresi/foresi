<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\ReceivedStatements;
use Foresi\Banking\Domain\ReceivedStatement;

final readonly class DoctrineReceivedStatements implements ReceivedStatements
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(ReceivedStatement $statement): void
    {
        $this->em->persist($statement);
    }

    public function chainOf(string $accountId, string $sourceId): array
    {
        /* @var list<ReceivedStatement> */
        return $this->em->createQuery('SELECT s FROM '.ReceivedStatement::class.' s WHERE s.account = :account AND s.sourceId = :source ORDER BY s.id')
            ->setParameters(['account' => $accountId, 'source' => $sourceId])
            ->getResult();
    }

    public function all(): array
    {
        /* @var list<ReceivedStatement> */
        return $this->em->createQuery('SELECT s, a FROM '.ReceivedStatement::class.' s JOIN s.account a ORDER BY a.position, a.id, s.id')->getResult();
    }
}
