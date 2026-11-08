<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A person a comment on a task can mention, for the @ list of the editor.
 */
final readonly class MentionCandidate
{
    /**
     * @param string $id the membership id, as MentionSyntax stores it
     */
    public function __construct(public string $id, public string $name, public string $initials)
    {
    }
}
