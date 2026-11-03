<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: sent documents found by the hash of their link, across tenants, for the
 * one page that is open to anyone with the link. It never makes a tenant active.
 */
interface PublicDocuments
{
    /**
     * Null for an unknown hash and for a send that was cancelled or whose links
     * were revoked, which the page answers alike.
     */
    public function find(string $tokenHash): ?PublicDocument;

    /**
     * Counts one opening of the PDF by the customer; the first one is kept as the
     * moment the document was read. One statement, past the audit log.
     */
    public function recordRead(string $sendId): void;

    /**
     * The tenant of a send, for the operator's commands.
     */
    public function tenantOf(string $sendId): ?string;
}
