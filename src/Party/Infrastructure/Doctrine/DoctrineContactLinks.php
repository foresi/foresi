<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\ContactLinks;
use Foresi\Party\Domain\ContactLink;

final readonly class DoctrineContactLinks implements ContactLinks
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(ContactLink $link): void
    {
        if ($this->exists($link)) {
            throw new \DomainException('The person is linked to this company already.');
        }

        $this->em->persist($link);
    }

    public function remove(ContactLink $link): void
    {
        $this->em->remove($link);
    }

    private function exists(ContactLink $link): bool
    {
        $uow = $this->em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof ContactLink && $pending !== $link && $pending->company() === $link->company() && $pending->person() === $link->person()) {
                return true;
            }
        }

        // Parties that are not stored yet have no stored links.
        if (!$this->em->contains($link->company()) || !$this->em->contains($link->person())
            || $uow->isScheduledForInsert($link->company()) || $uow->isScheduledForInsert($link->person())) {
            return false;
        }

        return (int) $this->em->createQuery('SELECT COUNT(l.id) FROM '.ContactLink::class.' l WHERE l.company = :company AND l.person = :person')
            ->setParameter('company', $link->company())
            ->setParameter('person', $link->person())
            ->getSingleScalarResult() > 0;
    }
}
