<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Activities;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Tests\Work\Application\WorkTestCase;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Integration\Activities\ProjectActivitiesSection;
use Foresi\Work\Integration\Activities\ProjectActivityTarget;

/**
 * A project as the record of an activity: its activities are seen by whoever
 * sees the project, and an archived project takes no new ones.
 */
final class ProjectActivityTargetTest extends WorkTestCase
{
    private ProjectActivityTarget $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->target = new ProjectActivityTarget($this->queries, self::getContainer()->get('router'));
    }

    public function testCanSeeFollowsTheProjectsVisibility(): void
    {
        $open = $this->project();
        $chosen = $this->projects->create('Client A rollout', null);
        /** @var ManageSharing $sharing */
        $sharing = self::getContainer()->get(ManageSharing::class);
        $sharing->changeVisibility($chosen->id(), ProjectVisibility::Chosen);

        // The organisation sees a project open to it, and the owner a private one.
        self::assertTrue($this->target->canSee('project', $open->id()));
        self::assertTrue($this->target->canSee('project', $chosen->id()));
        self::assertSame('Website', $this->target->label('project', $open->id()));
        self::assertSame('/projects/'.$open->id(), $this->target->url('project', $open->id()));

        $this->signIn($this->member);
        self::assertTrue($this->target->canSee('project', $open->id()));
        self::assertFalse($this->target->canSee('project', $chosen->id()), 'a private project is seen by the chosen people only');
        self::assertNull($this->target->label('project', $chosen->id()));
        self::assertNull($this->target->url('project', $chosen->id()));

        // A guest sees only what is shared with them.
        $this->signIn($this->guest);
        self::assertFalse($this->target->canSee('project', $open->id()));
        self::assertFalse($this->target->canLink('project', $open->id()));
        $this->signIn($this->owner);
        $sharing->shareProject($open->id(), $this->guest->id());
        $this->signIn($this->guest);
        self::assertTrue($this->target->canSee('project', $open->id()));
    }

    public function testCanLinkRefusesAnArchivedProject(): void
    {
        $project = $this->project();
        self::assertTrue($this->target->canLink('project', $project->id()));

        $this->projects->archive($project->id());

        self::assertFalse($this->target->canLink('project', $project->id()));
        self::assertTrue($this->target->canSee('project', $project->id()), 'its activities stay seen');
    }

    public function testTheActivitiesTabIsTheStaffsOnly(): void
    {
        $container = self::getContainer();
        $section = new ProjectActivitiesSection($container->get('router'), $container->get('translator'), $container->get(StaffCheck::class));
        $project = $this->project();
        $context = ['projectId' => $project->id(), 'clientId' => null, 'return' => '/projects/'.$project->id()];

        $items = [...$section->items(ProjectSlots::SECTIONS, $context)];
        self::assertCount(1, $items);
        self::assertSame('activities', $items[0]->data['key']);
        self::assertSame('/activities/for/project/'.$project->id().'?return=/projects/'.$project->id(), $items[0]->data['href']);
        self::assertSame([], [...$section->items(ProjectSlots::HEADER_FACTS, $context)]);

        // Activities is the staff's: a guest the project is shared with gets no tab.
        /** @var ManageSharing $sharing */
        $sharing = $container->get(ManageSharing::class);
        $sharing->shareProject($project->id(), $this->guest->id());
        $this->signIn($this->guest);
        self::assertSame([], [...$section->items(ProjectSlots::SECTIONS, $context)]);
    }

    public function testOtherKindsAndMalformedIdsAreNotProjects(): void
    {
        $project = $this->project();

        self::assertSame(['project'], $this->target->kinds());
        self::assertFalse($this->target->canSee('deal', $project->id()));
        self::assertFalse($this->target->canSee('project', 'not-an-id'));
        self::assertFalse($this->target->canSee('project', '01937f4e-0000-7000-8000-000000000001'));
    }
}
