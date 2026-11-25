<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract;

/**
 * The slots of the screens of Purchasing, filled by SlotProviders of other modules' integrations (ADR 0038).
 *
 * DOCUMENT_SECTIONS: panels of the page of a received document, under its figures and above its history.
 * Context: documentId (string); a recorded document that is not in the trash only. Each item's template is
 * included with the item's data and the document's id as "documentId".
 *
 * DOCUMENT_ACTIONS: rows of the "⋯" menu of the page of a draft, after Read again, offered only while the
 * draft can be read again. Context: documentId (string). Each item's template is a menu row, included with the
 * item's data, the document's id as "documentId" and the CSRF token of the page's forms as "token"; a form
 * that posts to a route of Purchasing, such as app_purchasing_read_again, uses that token.
 *
 * SETTINGS_SECTIONS: sections of Settings → Received documents, in the order of their weights. Context:
 * none. Each item's template is included with the item's data and renders one section; the page says so
 * itself when no module fills the slot.
 */
final class PurchasingSlots
{
    public const string DOCUMENT_SECTIONS = 'purchasing.document_sections';

    public const string DOCUMENT_ACTIONS = 'purchasing.document_actions';

    public const string SETTINGS_SECTIONS = 'purchasing.settings_sections';

    private function __construct()
    {
    }
}
