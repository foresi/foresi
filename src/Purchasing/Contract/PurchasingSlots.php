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
 */
final class PurchasingSlots
{
    public const string DOCUMENT_SECTIONS = 'purchasing.document_sections';

    private function __construct()
    {
    }
}
