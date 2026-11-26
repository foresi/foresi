<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Proposes the record an inbox item is likely about, from its matched contact
 * (the Pipedrive rule): Sales the one open deal of the client, Work its
 * one active project. A module implements it in Integration/Inbox. It proposes
 * only what it is sure of, and only what the person signed in may see.
 */
#[AutoconfigureTag(self::TAG)]
interface RecordSuggester
{
    public const string TAG = 'foresi.inbox.record_suggester';

    /** The kind of the records it proposes, such as deal. */
    public function kind(): string;

    /**
     * @param string      $contactId the matched contact
     * @param string|null $companyId the company of a matched person, when known
     *
     * @return SuggestedRecord|null the one record to pre-select, or null when there is none or several
     */
    public function suggest(string $contactId, ?string $companyId): ?SuggestedRecord;
}
