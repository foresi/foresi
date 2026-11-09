<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\WorkflowTemplate;

/**
 * The head of a project page: who sees it, whether the signed-in person may
 * share it or keep its settings, and whether they may add a task to it.
 */
final readonly class ProjectSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $clientId,
        public ?string $clientName,
        public string $description,
        public WorkflowTemplate $template,
        public ?\DateTimeImmutable $archivedAt,
        public ProjectVisibility $visibility = ProjectVisibility::Organisation,
        public bool $shareable = false,
        /** Whether the signed-in person keeps its settings, its trash and the address book behind its client. */
        public bool $manageable = false,
        /** Whether the signed-in person sees all of it that is not private, and so adds a task in no section. */
        public bool $open = false,
        /** Whether the signed-in person may add a task somewhere in it: the whole project or a section they see. */
        public bool $taskable = false,
    ) {
    }
}
