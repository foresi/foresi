<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\WorkflowTemplate;

/**
 * The head of a project page: who sees it, and whether the signed-in person
 * may share it.
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
    ) {
    }
}
