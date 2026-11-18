<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Work\Application\WorkShares;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkShare;

final readonly class DoctrineWorkShares implements WorkShares
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(Membership $membership): array
    {
        /** @var list<WorkShare> $shares */
        $shares = $this->entityManager
            ->createQuery('SELECT ws, p, s, sp, t, tp FROM '.WorkShare::class.' ws LEFT JOIN ws.project p LEFT JOIN ws.section s LEFT JOIN s.project sp LEFT JOIN ws.task t LEFT JOIN t.project tp WHERE ws.membership = :membership ORDER BY ws.createdAt ASC, ws.id ASC')
            ->setParameter('membership', $membership->id())
            ->getResult();

        return $shares;
    }

    public function on(Project|Section|Task $item): array
    {
        /** @var list<WorkShare> $shares */
        $shares = $this->entityManager
            ->createQuery('SELECT ws, m, u FROM '.WorkShare::class.' ws JOIN ws.membership m JOIN m.user u WHERE ws.'.$this->field($item).' = :item ORDER BY u.displayName ASC, ws.id ASC')
            ->setParameter('item', $item->id())
            ->getResult();

        return $shares;
    }

    public function find(Project|Section|Task $item, Membership $membership): ?WorkShare
    {
        return $this->entityManager->getRepository(WorkShare::class)->findOneBy([$this->field($item) => $item, 'membership' => $membership]);
    }

    public function add(WorkShare $share): void
    {
        $this->entityManager->persist($share);
    }

    public function remove(WorkShare $share): void
    {
        $this->entityManager->remove($share);
    }

    private function field(Project|Section|Task $item): string
    {
        return match (true) {
            $item instanceof Project => 'project',
            $item instanceof Section => 'section',
            $item instanceof Task => 'task',
        };
    }
}
