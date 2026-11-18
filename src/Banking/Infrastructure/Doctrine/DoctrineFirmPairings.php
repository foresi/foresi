<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\FirmPairings;
use Foresi\Banking\Application\PairingRules;
use Foresi\Banking\Domain\FirmPairing;
use Foresi\Party\Domain\LegalEntity;

/**
 * Through the ORM. A firm without a stored row has the defaults.
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
