<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An invoice line that rebills no occurrence after all.
 */
#[Operation(
    name: 'margin.rebill_pairings.unpair',
    method: 'DELETE',
    path: '/api/v1/margin/rebills/{invoiceLineId}',
    tool: 'foresi_unpair_rebill_line',
    scope: 'margin:write',
    title: 'Unpair an invoice line from the planned cost it rebills',
    description: 'Takes away the pairing of an invoice line with the occurrence of a planned cost, whether it was made on issue or by hand: the occurrence shows as "paid, not rebilled" again if the customer bears it and a cost document is paired with it. A line that is not paired is not found (unknown_pairing). The line itself and its price are not changed.',
    response: 'MarginRebillUnpaired',
    staffOnly: true,
)]
final readonly class UnpairRebillLineInput
{
    public function __construct(
        #[Field('The id of the line of the issued invoice.', format: 'uuid')]
        public string $invoiceLineId,
    ) {
    }
}
