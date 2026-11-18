<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\FirmSettings;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyFirmSettings;

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

    public function remove(PartyFirmSettings $settings): void
    {
        $this->em->remove($settings);
    }

    public function emails(Party $party, LegalEntity $firm): array
    {
        if (!$this->em->contains($party) || $this->em->getUnitOfWork()->isScheduledForInsert($party)) {
            return [];
        }

        /** @var list<PartyEmail> $emails */
        $emails = $this->em->createQuery('SELECT e FROM '.PartyEmail::class.' e WHERE e.party = :party AND e.legalEntity = :firm ORDER BY e.id')
            ->setParameter('party', $party)
            ->setParameter('firm', $firm)
            ->getResult();

        return $emails;
    }
}
