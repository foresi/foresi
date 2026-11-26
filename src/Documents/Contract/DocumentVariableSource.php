<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Values a document template can use, under one prefix: {{ client.name }} comes
 * from the source whose prefix is "client". A module that knows the records a
 * document links to implements this in Integration/Documents; Documents never
 * names it. Values are read when a document is generated and kept with it as a
 * snapshot, so a later change to the record never changes a generated document.
 */
#[AutoconfigureTag(self::TAG)]
interface DocumentVariableSource
{
    public const string TAG = 'foresi.documents.variable_source';

    /** The first part of every variable of this source, such as "firm", "client" or "deal". */
    public function prefix(): string;

    /**
     * What the template editor lists and the preview fills in.
     *
     * @return list<VariableDefinition>
     */
    public function definitions(): array;

    /**
     * The values for a document with these links: scalars and nested arrays only,
     * keyed by the names of the definitions; a name that is a path, such as
     * "custom.acme_fleet_industry", nests its value by the path (since 1.9).
     *
     * @param list<RecordLink> $links
     *
     * @return array<string, mixed>|null null when the source does not apply to these links
     */
    public function values(array $links): ?array;
}
