<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Mcp;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The versions of a supplier's default recipient, as GET /api/v1/margin/suppliers/{contactId}/default-recipient writes them.
 */
final readonly class SupplierDefaultRecipientTool implements ReadTool
{
    public function __construct(private AllocationReads $reads)
    {
    }

    public function name(): string
    {
        return 'foresi_get_supplier_default_recipient';
    }

    public function title(): string
    {
        return 'Get the default recipient of a supplier';
    }

    public function scope(): string
    {
        return 'margin:read';
    }

    public function description(): string
    {
        return 'Returns who bears the costs of a supplier by default: its versions, latest first, each with from_month (YYYY-MM), recipient ("own", a contact id, or null where the default ends), recipient_name and current (the version in force this month). '
            .'The default applies to a cost that no line, document or planned-item mapping claims. Set it with foresi_set_supplier_default_recipient. Find the supplier with foresi_search_contacts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['contact_id' => ToolArguments::idSchema('The id of the supplier, a contact, as foresi_search_contacts returns it.')],
            'required' => ['contact_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->reads->supplierDefault(ToolArguments::text($arguments, 'contact_id') ?? '');
        } catch (MarginRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MarginJson::supplierDefault($view), \sprintf('%d versions of the default recipient of the supplier.', \count($view->versions)));
    }
}
