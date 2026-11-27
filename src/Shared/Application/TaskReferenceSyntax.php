<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application;

/**
 * How a reference to a task is stored in Markdown: "[text](task:UUID)", a link
 * of its own scheme beside the mention's. The text is what the person selected,
 * unchanged but for a backslash before "[", "]" and "\"; the id says which task
 * it means. Only the page that knows the task (a meeting's minutes) shows more
 * than the text.
 */
final class TaskReferenceSyntax
{
    public const int MAX_TEXT = 500;

    /** The pattern without delimiters: the text, escaped, then the task id. */
    public const string PATTERN = '\[((?:\\\\[\[\]\\\\]|[^\[\]\\\\\r\n]){1,500})\]\(task:([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\)';

    private function __construct()
    {
    }

    /**
     * The token for a task with this text: line breaks become spaces, and "[", "]" and "\" are escaped.
     */
    public static function token(string $text, string $taskId): string
    {
        $text = trim((string) preg_replace('/[\r\n]+/', ' ', $text));

        return '['.addcslashes($text, '[]\\').'](task:'.strtolower($taskId).')';
    }

    /**
     * The text of a token as the person wrote it, the escapes taken away.
     */
    public static function text(string $escaped): string
    {
        return (string) preg_replace('/\\\\([\[\]\\\\])/', '$1', $escaped);
    }

    /**
     * The tasks the source refers to, each once, in the order of their first reference.
     *
     * @return list<string>
     */
    public static function ids(string $source): array
    {
        preg_match_all('/'.self::PATTERN.'/u', $source, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[2])));
    }
}
