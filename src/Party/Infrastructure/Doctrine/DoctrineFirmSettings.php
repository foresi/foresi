<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\FirmSettings;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyFirmSettings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFirmSettings implements FirmSettings
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(Party $party, LegalEntity $firm): ?PartyFirmSettings
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof PartyFirmSettings && $pending->party() === $party && $pending->legalEntity() === $firm) {
                return $pending;
            }
        }

        if (!$this->em->contains($party) || $this->em->getUnitOfWork()->isScheduledForInsert($party)) {
            return null;
        }

        $found = $this->em->createQuery('SELECT s FROM '.PartyFirmSettings::class.' s WHERE s.party = :party AND s.legalEntity = :firm')
            ->setParameter('party', $party)
            ->setParameter('firm', $firm)
            ->getOneOrNullResult();

        return $found instanceof PartyFirmSettings ? $found : null;
    }

    public function add(PartyFirmSettings $settings): void
    {
        $this->em->persist($settings);
    }
}
