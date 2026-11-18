<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * The imports that wrote contacts, served by the module that imports them
 * (tag TAG): the address book narrowed to "the contacts this import created",
 * with a banner that links back to the import. Without a provider, no list is
 * ever narrowed to an import.
 */
interface ContactImports
{
    public const string TAG = 'app.party_contact_imports';

    /** The name of the imported file; null when there is no such import. */
    public function fileName(string $importId): ?string;

    /** The page that sums the import up. */
    public function summaryUrl(string $importId): string;

    /**
     * A DQL condition that $partyId, an expression for the id of a party, names
     * a contact the import gave the outcome, with its parameters.
     *
     * @return array{string, array<string, string>}
     */
    public function condition(ImportedBy $import, string $partyId): array;
}
