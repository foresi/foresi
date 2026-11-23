<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

/**
 * A yes-or-no field of the list of received documents that another module decides, by the ids of the documents
 * it holds for. An integration implements it and adds the field to the list as a ListFieldCatalog.
 */
interface ReceivedDocumentIdFilter
{
    public const string ID_FILTER_TAG = 'app.purchasing_received_document_id_filter';

    /**
     * The key of the list field this decides.
     */
    public function field(): string;

    /**
     * @return list<string> the ids of the documents for which the field is true
     */
    public function documentIds(): array;
}
