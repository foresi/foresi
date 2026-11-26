<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Contract;

/**
 * An invoice or recurring invoice as Sales links a planned invoice of a won
 * deal to it. Since module API 1.7.
 */
final readonly class BillingDocument
{
    /**
     * @param string      $clientPartyId the contact it is issued to
     * @param string      $label         how a person names it: an invoice's number, a recurring invoice's name
     * @param bool        $active        an invoice not in the trash; a recurring invoice neither ended nor in the trash
     * @param string|null $url           the page it is read on
     */
    public function __construct(
        public BillingDocumentType $type,
        public string $id,
        public string $clientPartyId,
        public string $label,
        public bool $active,
        public ?string $url = null,
    ) {
    }
}
