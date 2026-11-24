<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

/**
 * The slots of the screens of Invoicing, filled by SlotProviders of other modules' integrations (ADR 0038).
 *
 * Each slot is asked once for every line of the document on the screen, so a module adds something to a line without
 * Invoicing knowing it. Only staff reach these screens. Every item's template is included with the item's data.
 *
 * RECURRING_LINE_BADGES: small badges under the text of a line of a recurring invoice. Context: templateId, lineId.
 * RECURRING_LINE_ACTIONS: entries of the row menu of a line of a recurring invoice (Ui:MenuItem). Context: templateId, lineId.
 * INVOICE_LINE_BADGES: small badges under the text of a line of an issued document. Context: invoiceId, lineId.
 * INVOICE_LINE_ACTIONS: entries of the row menu of a line of an issued document (Ui:MenuItem). Context: invoiceId, lineId, kind (the DocumentKind value).
 */
final class InvoicingSlots
{
    public const string RECURRING_LINE_BADGES = 'invoicing.recurring_line_badges';
    public const string RECURRING_LINE_ACTIONS = 'invoicing.recurring_line_actions';
    public const string INVOICE_LINE_BADGES = 'invoicing.invoice_line_badges';
    public const string INVOICE_LINE_ACTIONS = 'invoicing.invoice_line_actions';

    private function __construct()
    {
    }
}
