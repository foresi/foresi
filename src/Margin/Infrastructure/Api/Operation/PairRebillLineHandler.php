<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\PairRebillLineInput;
use Foresi\Margin\Application\RebillPairings;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * margin.rebill_pairings.pair: an invoice line paired with an occurrence by hand.
 */
final readonly class PairRebillLineHandler implements OperationHandler
{
    public function __construct(private RebillPairings $pairings)
    {
    }

    public static function input(): string
    {
        return PairRebillLineInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof PairRebillLineInput);

        return new OperationOutput(MarginJson::rebillPairing($this->pairings->pair($input->invoiceLineId, $input->plannedItemId, $input->occurrenceOn)), 'Paired the invoice line with the occurrence of the planned cost.');
    }
}
