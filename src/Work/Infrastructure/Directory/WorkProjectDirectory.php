<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Directory;

use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\ProjectDetail;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Contract\ProjectDirectory;
use Foresi\Work\Contract\ProjectSummary;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The contract over Work's own reads (WorkQueries), so the rule of who sees a
 * project stays in one place.
 */
final readonly class WorkProjectDirectory implements ProjectDirectory
{
    public function __construct(private WorkQueries $queries, private UrlGeneratorInterface $urls)
    {
    }

    public function find(string $id): ?ProjectSummary
    {
        $detail = Id::isValid($id) ? $this->queries->project($id) : null;

        return $detail instanceof ProjectDetail ? $this->summary($detail->row) : null;
    }

    public function ofClient(?string $clientId, bool $activeOnly = true): array
    {
        $rows = $activeOnly ? $this->queries->projects() : [...$this->queries->projects(), ...$this->queries->projects(true)];
        $rows = array_values(array_filter($rows, static fn (ProjectRow $row): bool => $row->clientId === $clientId));
        usort($rows, static fn (ProjectRow $a, ProjectRow $b): int => strnatcasecmp($a->name, $b->name));

        return array_map($this->summary(...), $rows);
    }

    private function summary(ProjectRow $row): ProjectSummary
    {
        return new ProjectSummary($row->id, $row->name, $row->clientId, $row->archived, $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $row->id]));
    }
}
