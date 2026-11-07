<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Domain;

use App\Shared\Domain\Id;
use App\Tests\Party\Domain\PartyFactory;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    public function testANewProjectTrimsItsNameAndIsInternalByDefault(): void
    {
        $createdBy = Id::generate();
        $created = new \DateTimeImmutable('2026-11-07 08:00');
        $project = new Project('  Website  ', null, WorkflowTemplate::Development, $createdBy, $created);

        self::assertSame('Website', $project->name());
        self::assertSame('', $project->description());
        self::assertNull($project->client());
        self::assertSame(WorkflowTemplate::Development, $project->workflowTemplate());
        self::assertSame($createdBy, $project->createdBy());
        self::assertSame($created, $project->createdAt());
        self::assertFalse($project->isArchived());
        self::assertTrue(Id::isValid($project->id()));
    }

    public function testChanges(): void
    {
        $project = self::project();
        $client = PartyFactory::person();

        $project->rename(' Shop ');
        $project->describe('The new shop');
        $project->assignClient($client);

        self::assertSame('Shop', $project->name());
        self::assertSame('The new shop', $project->description());
        self::assertSame($client, $project->client());

        $project->assignClient(null);
        self::assertNull($project->client());
    }

    public function testArchivingKeepsTheFirstDateAndRestoringClearsIt(): void
    {
        $project = self::project();
        $first = new \DateTimeImmutable('2026-11-07');

        $project->archive($first);
        $project->archive(new \DateTimeImmutable('2026-11-08'));
        self::assertTrue($project->isArchived());
        self::assertSame($first, $project->archivedAt());

        $project->restore();
        self::assertFalse($project->isArchived());
        self::assertNull($project->archivedAt());
    }

    /**
     * @param \Closure(): object $name
     */
    #[DataProvider('invalidNames')]
    public function testNamesCannotBeEmptyOrTooLong(\Closure $name): void
    {
        $this->expectException(\DomainException::class);
        $name();
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty project' => [static fn (): Project => self::project(' ')];
        yield 'long project' => [static fn (): Project => self::project(str_repeat('a', Project::MAX_NAME_LENGTH + 1))];
        yield 'empty status' => [static fn (): ProjectStatus => new ProjectStatus(self::project(), "\t", StatusType::Open, 0)];
        yield 'long status' => [static fn (): ProjectStatus => new ProjectStatus(self::project(), str_repeat('a', ProjectStatus::MAX_NAME_LENGTH + 1), StatusType::Open, 0)];
        yield 'empty section' => [static fn (): Section => new Section(self::project(), '', 0)];
        yield 'long section' => [static fn (): Section => new Section(self::project(), str_repeat('a', Section::MAX_NAME_LENGTH + 1), 0)];
        yield 'empty label' => [static fn (): Label => new Label(' ', LabelColour::Gray)];
        yield 'long label' => [static fn (): Label => new Label(str_repeat('a', Label::MAX_NAME_LENGTH + 1), LabelColour::Gray)];
    }

    public function testNamesOfTheMaximumLengthAreAccepted(): void
    {
        self::assertSame(Project::MAX_NAME_LENGTH, mb_strlen(self::project(str_repeat('č', Project::MAX_NAME_LENGTH))->name()));
        self::assertSame(Label::MAX_NAME_LENGTH, mb_strlen(new Label(str_repeat('č', Label::MAX_NAME_LENGTH), LabelColour::Red)->name()));
    }

    public function testAStatusIsRenamedRetypedAndMovedUnderItsProject(): void
    {
        $project = self::project();
        $status = new ProjectStatus($project, ' Review ', StatusType::Active, 2);

        self::assertSame('Review', $status->name());
        self::assertSame($project, $status->auditRoot());

        $status->rename('Approved');
        $status->retype(StatusType::Done);
        $status->moveTo(5);

        self::assertSame('Approved', $status->name());
        self::assertSame(StatusType::Done, $status->type());
        self::assertSame(5, $status->position());
    }

    public function testASectionIsRecordedUnderItsProject(): void
    {
        $project = self::project();
        $section = new Section($project, ' Design ', 1);

        self::assertSame('Design', $section->name());
        self::assertSame($project, $section->auditRoot());
        $section->moveTo(3);
        self::assertSame(3, $section->position());
    }

    public function testALabelOnATaskIsRecordedUnderTheTask(): void
    {
        $project = self::project();
        $task = new Task($project, 'Task', new ProjectStatus($project, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable());
        $label = new Label(' Bug ', LabelColour::Red);
        $label->recolour(LabelColour::Amber);
        $taskLabel = new TaskLabel($task, $label);

        self::assertSame('Bug', $label->name());
        self::assertSame(LabelColour::Amber, $label->colour());
        self::assertSame($task, $taskLabel->auditRoot());
        self::assertSame($label, $taskLabel->label());
    }

    public function testOnlyDoneAndClosedAreFinished(): void
    {
        self::assertFalse(StatusType::Open->isFinished());
        self::assertFalse(StatusType::Active->isFinished());
        self::assertTrue(StatusType::Done->isFinished());
        self::assertTrue(StatusType::Closed->isFinished());
    }

    public function testPrioritiesRankFromNoneToUrgent(): void
    {
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn (Priority $priority): int => $priority->rank(), Priority::cases()));
    }

    public function testTheTemplatesSeedTheirStatuses(): void
    {
        self::assertSame([
            ['work.status.to_do', StatusType::Open],
            ['work.status.in_progress', StatusType::Active],
            ['work.status.done', StatusType::Done],
        ], WorkflowTemplate::Simple->statuses());

        self::assertSame([
            ['work.status.open', StatusType::Open],
            ['work.status.in_progress', StatusType::Active],
            ['work.status.review', StatusType::Active],
            ['work.status.testing', StatusType::Active],
            ['work.status.blocked', StatusType::Active],
            ['work.status.done', StatusType::Done],
            ['work.status.closed', StatusType::Closed],
        ], WorkflowTemplate::Development->statuses());
    }

    private static function project(string $name = 'Website'): Project
    {
        return new Project($name, null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
    }
}
