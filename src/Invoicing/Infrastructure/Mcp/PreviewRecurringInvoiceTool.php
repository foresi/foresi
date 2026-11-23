<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mcp;

use Foresi\Invoicing\Infrastructure\Api\RecurringInvoicePreviews;
use Foresi\Shared\Application\Refused;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * foresi_preview_recurring_invoice: what a create would save and issue, as the
 * preview of the form shows it. Nothing is saved.
 */
final readonly class PreviewRecurringInvoiceTool implements ReadTool
{
    public function __construct(private RecurringInvoicePreviews $previews)
    {
    }

    public function name(): string
    {
        return 'foresi_preview_recurring_invoice';
    }

    public function title(): string
    {
        return 'Preview a recurring invoice';
    }

    public function scope(): string
    {
        return 'invoices:read';
    }

    public function description(): string
    {
        return 'Shows what foresi_create_recurring_invoice would save with the same fields, without saving anything: the customer, texts, schedule, delivery and lines it would have, '
            .'and next_issues, the next invoices it would issue with their issue, tax point and due dates and description. '
            .'saves is false when the rules would refuse it, with every problem in problems as "parameter: reason — message". '
            .'With from_invoice_id it shows the lines an invoice gives, in the shape foresi_create_invoice takes them: drop or change some and send them as lines. count sets how many issues it shows.';
    }

    public function inputSchema(): array
    {
        return $this->previews->schema();
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $draft = $this->previews->of($arguments);
        } catch (Refused $refused) {
            throw ToolRefusal::of($refused);
        }
        $issues = \is_array($draft['next_issues'] ?? null) ? \count($draft['next_issues']) : 0;

        return new ToolOutput($draft, true === $draft['saves'] ? \sprintf('It saves, and issues %d invoices in the preview. Nothing was saved.', $issues) : 'The rules would refuse it; problems lists why. Nothing was saved.');
    }
}
