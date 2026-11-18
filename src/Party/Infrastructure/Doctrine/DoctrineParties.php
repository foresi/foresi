<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\Parties;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;

final readonly class DoctrineParties implements Parties
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function register(Party $party, PartyAddress $billing, array $emails): void
    {
        $this->em->persist($party);
        $this->em->persist($billing);
        foreach ($emails as $email) {
            $this->em->persist($email);
        }
        $this->em->flush();
    }

    public function addEmail(PartyEmail $email): void
    {
        $this->em->persist($email);
    }

    public function removeEmail(PartyEmail $email): void
    {
        $this->em->remove($email);
    }
}
