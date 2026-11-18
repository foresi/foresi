<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Application\View\SavedViewStore;
use Foresi\Shared\Domain\View\SavedView;

final readonly class DoctrineSavedViewStore implements SavedViewStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function ofList(string $listKey): array
    {
        /* @var list<SavedView> */
        return $this->em->createQuery('SELECT v FROM '.SavedView::class.' v WHERE v.listKey = :list ORDER BY v.position ASC, v.createdAt ASC, v.id ASC')
            ->setParameter('list', $listKey)
            ->getResult();
    }

    public function bySystemKey(string $systemKey): ?SavedView
    {
        return $this->em->getRepository(SavedView::class)->findOneBy(['systemKey' => $systemKey]);
    }

    public function add(SavedView $view): void
    {
        $this->em->persist($view);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
