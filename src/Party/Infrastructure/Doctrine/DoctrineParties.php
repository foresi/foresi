<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\Parties;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use Doctrine\ORM\EntityManagerInterface;

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
}
