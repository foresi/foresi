<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\RebillPairings;
use Foresi\Margin\Application\UnpairRebillLineInput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * margin.rebill_pairings.unpair: an invoice line that rebills nothing.
 */
final readonly class UnpairRebillLineHandler implements OperationHandler
{
    public function __construct(private RebillPairings $pairings)
    {
    }

    public static function input(): string
    {
        return UnpairRebillLineInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UnpairRebillLineInput);
        $this->pairings->unpair($input->invoiceLineId);

        return new OperationOutput(['invoice_line_id' => $input->invoiceLineId, 'unpaired' => true], 'Unpaired the invoice line.');
    }
}
