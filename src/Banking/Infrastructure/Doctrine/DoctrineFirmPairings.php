<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\FirmPairingRules;
use App\Banking\Application\FirmPairings;
use App\Banking\Application\PairingRules;
use App\Banking\Domain\FirmPairing;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tenant-scoped through the Doctrine tenant filter. A firm without a stored row
 * has the defaults.
 */
final readonly class DoctrineFirmPairings implements FirmPairings, FirmPairingRules
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function of(LegalEntity $firm): ?FirmPairing
    {
        $pairing = $this->em->createQuery('SELECT p FROM '.FirmPairing::class.' p WHERE p.firm = :firm')
            ->setParameter('firm', $firm)
            ->getOneOrNullResult();

        return $pairing instanceof FirmPairing ? $pairing : null;
    }

    public function add(FirmPairing $pairing): void
    {
        $this->em->persist($pairing);
    }

    public function for(LegalEntity $firm): PairingRules
    {
        $pairing = $this->of($firm);

        return $pairing instanceof FirmPairing
            ? new PairingRules($pairing->autoPairing(), $pairing->smallDifference())
            : new PairingRules(FirmPairing::DEFAULT_AUTO_PAIRING, FirmPairing::DEFAULT_SMALL_DIFFERENCE);
    }
}
