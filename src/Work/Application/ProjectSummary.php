<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\WorkflowTemplate;

/**
 * The head of a project page.
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
    ) {
    }
}
