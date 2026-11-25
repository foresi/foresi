<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Where the firm pays its VAT, so its payments are matched to the periods.
 */
#[Operation(
    name: 'vat.settings.set',
    method: 'PUT',
    path: '/api/v1/vat/settings',
    tool: 'foresi_set_vat_settings',
    scope: 'vat:write',
    title: 'Set the VAT payment details',
    description: 'Sets where the firm pays its VAT: the account of its tax office and the variable symbol, which match the bank payments to the VAT periods. It replaces what was set. An empty variable symbol takes the one the country suggests, in the Czech Republic the digits of the VAT ID. GET /api/v1/vat/settings shows the suggested account, which the person completes with the number of their own tax office.',
    response: 'VatSettings',
    staffOnly: true,
)]
final readonly class SetVatSettingsInput
{
    public function __construct(
        #[Field('The account of the tax office, such as "705-12345678/0710" or an IBAN.', maxLength: 64)]
        public string $officeAccount,
        #[Field('The variable symbol or payment reference; empty takes the suggested one.', maxLength: 35)]
        public string $variableSymbol = '',
    ) {
    }
}
