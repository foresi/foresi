<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Domain;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

final class TenantStampTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testTheTenantOfWorkIsKnownOnlyOncePersisted(): void
    {
        $project = new Project('Website', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable());
        $status = new ProjectStatus($project, 'To do', StatusType::Open, 0);
        $task = new Task($project, 'Write the brief', $status, 0, Id::generate(), new \DateTimeImmutable());
        $label = new Label('Urgent', LabelColour::Red);
        $membership = new Membership(new User('testing@example.com', 'hash', 'Test Person'), Role::Member);

        foreach ([$project, $status, $task, $label, new Section($project, 'Design', 0), new TaskLabel($task, $label), new TaskWatcher($task, $membership)] as $entity) {
            try {
                $entity->tenantId();
                self::fail(sprintf('%s answered a tenant before it was stamped.', $entity::class));
            } catch (\LogicException) {
            }
        }
    }
}
