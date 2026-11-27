<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\FirmPairing;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Shared\Application\TransactionalWork;

/**
 * The one path that writes the pairing switches. The first change stores
 * the row; until then the defaults apply.
 */
final readonly class ChangePairing
{
    public function __construct(
        private InstanceFirm $firm,
        private FirmPairings $pairings,
        private TransactionalWork $transaction,
    ) {
    }

    /**
     * @throws NoFirmYet before the first-run setup
     */
    public function change(bool $autoPairing, bool $smallDifference): void
    {
        $this->transaction->run(function () use ($autoPairing, $smallDifference): void {
            // Two first changes at once would both find no row and both store one.
            $this->firm->lock();
            $pairing = $this->pairings->stored();
            if ($pairing instanceof FirmPairing) {
                $pairing->change($autoPairing, $smallDifference);

                return;
            }
            $this->pairings->add(FirmPairing::of($autoPairing, $smallDifference));
        });
    }
}
