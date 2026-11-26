<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Where the owner pays an authority, so the payments of its advances are
 * matched to the estimate.
 */
#[Operation(
    name: 'taxes.profile.authority.set',
    method: 'PUT',
    path: '/api/v1/taxes-and-contributions/profile/authorities/{authority}',
    tool: 'foresi_set_tax_authority_account',
    scope: 'taxes:write',
    title: 'Set the account of an authority',
    description: 'Sets where the owner pays social insurance, health insurance, sickness insurance or the income tax: the account and the variable symbol, which match the payments of the advances to the estimate. It replaces what was set. Only the owner of the organisation may do this.',
    response: 'TaxAuthorityAccount',
    staffOnly: true,
)]
final readonly class SetAuthorityAccountInput
{
    public function __construct(
        #[Field('The authority.', enum: ['social', 'health', 'sickness', 'income_tax', 'flat_rate'])]
        public string $authority,
        #[Field('The account, such as "000000-1234567890/0710" or an IBAN.', maxLength: 64)]
        public string $account,
        #[Field('The variable symbol or payment reference; empty when the authority needs none.', maxLength: 35)]
        public string $variableSymbol = '',
    ) {
    }
}
