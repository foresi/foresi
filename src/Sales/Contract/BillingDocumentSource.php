<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that issues invoices, so a planned invoice of a won deal can be
 * linked to one that exists already (ADR 0033): the module implements it in
 * its Integration/Sales directory, and Sales knows it by its tag only.
 * Since module API 1.8.
 */
#[AutoconfigureTag(self::TAG)]
interface BillingDocumentSource
{
    public const string TAG = 'foresi.sales.billing_document_source';

    /**
     * An invoice or recurring invoice, in the trash or ended too; null for an unknown id.
     */
    public function find(BillingDocumentType $type, string $id): ?BillingDocument;

    /**
     * The active ones issued to the client, the newest first, at most $limit.
     *
     * @return list<BillingDocument>
     */
    public function ofClient(BillingDocumentType $type, string $clientPartyId, int $limit): array;
}
