<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Domain\RebillPairing;

/**
 * Takes the rebill pairings of an invoice away when it goes to the trash, so
 * what it rebilled shows as unrebilled again; PairRebillOnIssue pairs its lines
 * once more when it is restored (a pairing a person made by hand is not
 * restored). Persists and leaves the flush to the delivery's unit of work.
 */
final readonly class DropRebillOnTrash
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return int how many pairings were removed
     */
    public function drop(string $invoiceId): int
    {
        $pairings = $this->entityManager->getRepository(RebillPairing::class)->findBy(['invoiceId' => $invoiceId]);
        foreach ($pairings as $pairing) {
            $this->entityManager->remove($pairing);
        }

        return \count($pairings);
    }
}
