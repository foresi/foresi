<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Mcp;

use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;

/**
 * The owner profile, as GET /api/v1/taxes-and-contributions/profile writes it.
 */
final readonly class GetTaxProfileTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ProfileReads $reads)
    {
    }

    public function name(): string
    {
        return 'foresi_get_tax_profile';
    }

    public function title(): string
    {
        return 'Get the tax profile';
    }

    public function scope(): string
    {
        return 'taxes:read';
    }

    public function description(): string
    {
        return 'Returns the owner profile the taxes and contributions are estimated from: {statuses} with the id, kind, value, valid_from and base of each dated status (a status holds until the next of its kind), {years} with the inputs of each year, and {authorities} with the account and variable symbol of each authority. '
            .'Only the owner of the organisation may read it: it holds personal data. Change it with foresi_add_tax_status, foresi_remove_tax_status, foresi_set_tax_year and foresi_set_tax_authority_account.';
    }

    /**
     * The owner's own data, never a guest's (ADR 0041).
     */
    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $profile = $this->reads->profile();
        } catch (TaxesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($profile->statuses);

        return new ToolOutput(TaxesJson::profile($profile), 1 === $count ? 'The owner profile has 1 status.' : \sprintf('The owner profile has %d statuses.', $count));
    }
}
