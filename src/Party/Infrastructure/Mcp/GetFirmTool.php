<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Mcp;

use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Application\Query\FirmDetails;
use Foresi\Party\Infrastructure\Api\FirmJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;

/**
 * The one firm of the instance (ADR 0039), as GET /api/v1/firm writes it.
 */
final readonly class GetFirmTool implements ReadTool
{
    public function __construct(private FirmDetails $firm)
    {
    }

    public function name(): string
    {
        return 'foresi_get_firm';
    }

    public function title(): string
    {
        return 'Get the firm';
    }

    public function scope(): string
    {
        return 'firm:read';
    }

    public function description(): string
    {
        return 'Returns the firm of this Foresi instance, the company that issues its documents and receives its payments: id, name, registration number, VAT ID, the VAT status of today, register entry and address, '
            .'and vat_statuses: the VAT status over time, oldest first, each {id, status, period_length, valid_from} holding from its day until the next one (valid_from null: from the start; period_length month or quarter for a registered payer). '
            .'An instance holds exactly one firm, so no other tool takes a firm id.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $firm = $this->firm->get();
        } catch (PartyNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its firm.');
        } catch (NoFirmYet) {
            throw ToolRefusal::notFound('The instance has no firm yet: its first-run setup has not happened.');
        }

        return new ToolOutput(FirmJson::detail($firm, $this->firm->vatStatuses()), 'The firm of the instance.');
    }
}
