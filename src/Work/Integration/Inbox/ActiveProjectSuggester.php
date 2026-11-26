<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Inbox\Contract\RecordSuggester;
use Foresi\Inbox\Contract\SuggestedRecord;
use Foresi\Work\Application\ClientProjects;
use Foresi\Work\Contract\ProjectDirectory;

/**
 * The one active project of the client of an inbox item, when the person sees
 * exactly one; none or several propose nothing.
 */
final readonly class ActiveProjectSuggester implements RecordSuggester
{
    public const string KIND = ProjectDirectory::KIND;

    public function __construct(private ClientProjects $projects)
    {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function suggest(string $contactId, ?string $companyId): ?SuggestedRecord
    {
        $projects = $this->projects->of($contactId, $companyId);

        return 1 === \count($projects) ? new SuggestedRecord(self::KIND, $projects[0]->id) : null;
    }
}
