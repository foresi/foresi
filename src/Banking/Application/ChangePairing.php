<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\FirmPairing;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;

/**
 * The one path that writes a firm's pairing switches. The first change stores
 * the firm's row; until then it has the defaults.
 */
final readonly class ChangePairing
{
    public function __construct(
        private LegalEntities $firms,
        private FirmPairings $pairings,
        private TransactionalWork $transaction,
    ) {
    }

    /**
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function change(string $firmId, bool $autoPairing, bool $smallDifference): void
    {
        $this->transaction->run(function () use ($firmId, $autoPairing, $smallDifference): void {
            $firm = $this->firms->get($firmId);
            $pairing = $this->pairings->of($firm);
            if ($pairing instanceof FirmPairing) {
                $pairing->change($autoPairing, $smallDifference);

                return;
            }
            $this->pairings->add(FirmPairing::of($firm, $autoPairing, $smallDifference));
        });
    }
}
