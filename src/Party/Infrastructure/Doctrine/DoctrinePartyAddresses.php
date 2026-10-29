<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\PartyAddresses;
use App\Party\Domain\AddressType;
use App\Party\Domain\PartyAddress;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePartyAddresses implements PartyAddresses
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(PartyAddress $address): void
    {
        if (AddressType::Billing === $address->type() && $this->hasBilling($address)) {
            throw new \DomainException('The party has a billing address already.');
        }

        $this->em->persist($address);
    }

    private function hasBilling(PartyAddress $address): bool
    {
        $party = $address->party();
        // A party that is not stored yet has no addresses; the unique index backs this up.
        if (!$this->em->contains($party) || null === $this->em->getUnitOfWork()->getSingleIdentifierValue($party)) {
            return false;
        }

        return (int) $this->em->createQuery('SELECT COUNT(a.id) FROM '.PartyAddress::class.' a WHERE a.party = :party AND a.type = :type')
            ->setParameter('party', $party)
            ->setParameter('type', AddressType::Billing)
            ->getSingleScalarResult() > 0;
    }
}
