<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * How a mention of a person is stored in Markdown: "@[Display Name](membership:UUID)".
 * The name is the one shown when the text was written; the id says whom it means.
 */
final class MentionSyntax
{
    /** The pattern without delimiters: the display name, then the membership id. */
    public const string PATTERN = '@\[([^\]\[\r\n]{1,200})\]\(membership:([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\)';

    /**
     * The memberships the source mentions, each once, in the order of their first mention.
     *
     * @return list<string>
     */
    public static function ids(string $source): array
    {
        preg_match_all('/'.self::PATTERN.'/u', $source, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[2])));
    }
}
