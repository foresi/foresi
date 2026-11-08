<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Party;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\WorkflowTemplate;
use Psr\Clock\ClockInterface;

/**
 * Starting a project, changing what it is and for whom, and archiving it.
 */
final readonly class ManageProjects
{
    public function __construct(
        private Projects $projects,
        private VisibleWork $work,
        private PartyQuery $parties,
        private StatusNames $statusNames,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * A new project with the statuses of its template, named in the language
     * of the person creating it.
     *
     * @throws WorkRefused
     */
    public function create(string $name, ?string $clientId, WorkflowTemplate $template, string $description = ''): Project
    {
        $this->work->assertManager();
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $client = $this->client($clientId);
        $project = WorkRefused::unlessNamed(fn (): Project => new Project($name, $client, $template, $userId, $this->clock->now()));
        $project->describe($description);

        return $this->unit->run(function () use ($project, $template): Project {
            $this->projects->add($project);
            foreach ($template->statuses() as $position => [$key, $type]) {
                $this->projects->add(new ProjectStatus($project, $this->statusNames->of($key), $type, $position));
            }

            return $project;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function edit(string $projectId, string $name, ?string $clientId, string $description): void
    {
        $project = $this->work->project($projectId);
        $client = $this->client($clientId);
        WorkRefused::unlessNamed(static fn () => $project->rename($name));
        $this->unit->run(static function () use ($project, $client, $description): void {
            $project->assignClient($client);
            $project->describe($description);
        });
    }

    /**
     * @throws WorkRefused
     */
    public function archive(string $projectId): void
    {
        $project = $this->work->project($projectId);
        $this->unit->run(fn () => $project->archive($this->clock->now()));
    }

    /**
     * @throws WorkRefused
     */
    public function restore(string $projectId): void
    {
        $project = $this->work->project($projectId);
        $this->unit->run(static fn () => $project->restore());
    }

    private function client(?string $clientId): ?Party
    {
        if (null === $clientId || '' === $clientId) {
            return null;
        }

        return $this->parties->find($clientId) ?? throw new WorkRefused('client');
    }
}
