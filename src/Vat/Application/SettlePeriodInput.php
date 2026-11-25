<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A period marked settled by a person, such as one paid from an account the
 * bank does not show.
 */
#[Operation(
    name: 'vat.period.settle',
    method: 'POST',
    path: '/api/v1/vat/periods/{key}/settle',
    tool: 'foresi_settle_vat_period',
    scope: 'vat:write',
    title: 'Mark a VAT period settled',
    description: 'Marks a VAT period settled by hand, with an optional note, such as a period paid from an account the bank does not show or one offset by the tax office. A settled period has no flow in the cash-flow forecast and takes no more payments from the automatic matching. A bank payment settles a period on its own; this is for the others. Marking it again replaces the note.',
    response: 'VatPeriodDetail',
    staffOnly: true,
)]
final readonly class SettlePeriodInput
{
    public function __construct(
        #[Field('The period, a month such as "2026-11" or a quarter such as "2026-Q4".', maxLength: 7)]
        public string $key,
        #[Field('Why it is settled, at most 500 characters; empty for none.', maxLength: 500)]
        public string $note = '',
    ) {
    }
}
