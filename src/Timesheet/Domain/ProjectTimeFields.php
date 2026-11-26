<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Contract\Field\CustomField;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Work\Contract\Field\ProjectExtension;

/**
 * The fields of time on a project (ADR 0045), shown wherever the project's
 * custom fields are: its settings, its list, the REST API and MCP as
 * timesheet_billable_by_default. Time logged on the project is billable unless
 * said otherwise when the field is set (Kimai, Toggl); each entry may differ
 * from it. Its id is the project's id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_time_field')]
class ProjectTimeFields implements ProjectExtension
{
    #[ORM\Column(name: 'billable_by_default', type: 'boolean', nullable: true)]
    #[CustomField(FieldKind::Boolean, 'timesheet.project.field.billable', filterable: true, sortable: true)]
    private ?bool $billableByDefault = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
    ) {
    }

    public function projectId(): string
    {
        return $this->id;
    }

    public function billsByDefault(): bool
    {
        return true === $this->billableByDefault;
    }
}
