<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\ContactLinks;
use App\Party\Domain\ContactLink;
use Doctrine\ORM\EntityManagerInterface;

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

    private function exists(ContactLink $link): bool
    {
        $uow = $this->em->getUnitOfWork();
        // Parties that are not stored yet cannot be linked yet; the unique index backs this up.
        if (!$this->em->contains($link->company()) || !$this->em->contains($link->person())
            || null === $uow->getSingleIdentifierValue($link->company()) || null === $uow->getSingleIdentifierValue($link->person())) {
            return false;
        }

        return (int) $this->em->createQuery('SELECT COUNT(l.id) FROM '.ContactLink::class.' l WHERE l.company = :company AND l.person = :person')
            ->setParameter('company', $link->company())
            ->setParameter('person', $link->person())
            ->getSingleScalarResult() > 0;
    }
}
