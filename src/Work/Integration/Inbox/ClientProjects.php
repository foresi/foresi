<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;

/**
 * The active projects of the client an inbox item is matched to, that the
 * person signed in sees: the company's when the item has one, else the
 * person's own.
 */
final readonly class ClientProjects
{
    public function __construct(private WorkQueries $queries)
    {
    }

    /**
     * @return list<ProjectRow> by name; none for an item without a client
     */
    public function of(?string $contactId, ?string $companyId): array
    {
        $client = $companyId ?? $contactId;
        if (null === $client) {
            return [];
        }

        return array_values(array_filter($this->queries->projects(), static fn (ProjectRow $project): bool => $project->clientId === $client));
    }

    /**
     * The choices of a project field, project id to name: the client's active
     * projects, or all the active projects the person sees when the client has
     * none (or the mail is matched to nobody), so a project can always be picked.
     *
     * @return array<string, string>
     */
    public function choices(?string $contactId, ?string $companyId): array
    {
        $projects = $this->of($contactId, $companyId);
        if ([] === $projects) {
            $projects = $this->queries->projects();
        }
        $choices = [];
        foreach ($projects as $project) {
            $choices[$project->id] = $project->name;
        }

        return $choices;
    }
}
