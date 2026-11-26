<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Work\Contract\Event\ProjectUpdated;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\WorkflowTemplate;
use Psr\Clock\ClockInterface;

/**
 * Starting a project, changing what it is and for whom, and archiving it. The
 * custom fields of a project (ADR 0045) are written with it in the same unit of
 * work; each edit records work.project.updated, which names the keys of the
 * custom fields that changed.
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
        private CustomFieldWrites $customFields,
        private EventRecorder $events,
    ) {
    }

    /**
     * A new project with the statuses of its template, the simple one unless
     * told otherwise, named in the language of the person creating it.
     *
     * @param array<string, mixed>|null $customFields the custom fields by key, each required one with a value; null when the caller offers none
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
     */
    public function create(string $name, ?string $clientId, WorkflowTemplate $template = WorkflowTemplate::Simple, string $description = '', ?array $customFields = null): Project
    {
        $this->work->assertManager();
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $client = $this->client($clientId);
        $project = WorkRefused::unlessNamed(fn (): Project => new Project($name, $client, $template, $userId, $this->clock->now()));
        $project->describe($description);

        return $this->unit->run(function () use ($project, $template, $customFields): Project {
            $this->projects->add($project);
            foreach ($template->statuses() as $position => [$key, $type]) {
                $this->projects->add(new ProjectStatus($project, $this->statusNames->of($key), $type, $position));
            }
            if (null !== $customFields) {
                $this->customFields->write(ProjectExtension::RECORD, $project->id(), $customFields, true);
            }

            return $project;
        });
    }

    /**
     * @param array<string, mixed>|null $customFields the custom fields to change by key, null clearing one; null when the caller offers none
     *
     * @throws WorkRefused
     * @throws FieldRefused nothing is stored
     */
    public function edit(string $projectId, string $name, ?string $clientId, string $description, ?array $customFields = null): void
    {
        $project = $this->work->managedProject($projectId);
        $client = $this->client($clientId);
        WorkRefused::unlessNamed(static fn () => $project->rename($name));
        $this->unit->run(function () use ($project, $client, $description, $customFields): void {
            $project->assignClient($client);
            $project->describe($description);
            $changed = null === $customFields ? [] : $this->customFields->write(ProjectExtension::RECORD, $project->id(), $customFields, false);
            $this->events->record(new ProjectUpdated($project->id(), $changed));
        });
    }

    /**
     * @throws WorkRefused
     */
    public function archive(string $projectId): void
    {
        $project = $this->work->managedProject($projectId);
        $this->unit->run(fn () => $project->archive($this->clock->now()));
    }

    /**
     * @throws WorkRefused
     */
    public function restore(string $projectId): void
    {
        $project = $this->work->managedProject($projectId);
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
