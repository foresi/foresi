<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The records of some kinds a document can link to. The module that owns them
 * implements this in Integration/Documents. A record that goes away (archived,
 * trashed, deleted) keeps its links: exists() then answers false and the
 * document shows the link as unavailable.
 */
#[AutoconfigureTag(self::TAG)]
interface DocumentLinkTarget
{
    public const string TAG = 'foresi.documents.link_target';

    /**
     * @return list<string> the record kinds this target answers for, such as ["contact"]
     */
    public function kinds(): array;

    /** Whether the record exists and the person may see it. */
    public function exists(string $kind, string $id): bool;

    /** The name to show for the record, or null when it is gone. */
    public function label(string $kind, string $id): ?string;

    /** The screen of the record, or null when it is gone. */
    public function url(string $kind, string $id): ?string;
}
