<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Doctrine\DBAL\Connection;
use Foresi\Events\Infrastructure\Relay\EventRelay;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Shared\Domain\Id;
use Foresi\Webhooks\Domain\EndpointUrl;
use Foresi\Webhooks\Domain\EventTypes;
use Foresi\Webhooks\Domain\WebhookEndpoint;
use Foresi\Work\Infrastructure\Mcp\GetTaskTool;
use Foresi\Work\Infrastructure\Mcp\SearchProjectsTool;
use Foresi\Work\Infrastructure\Mcp\SearchTasksTool;

/**
 * A token acts as its person, so the API shows what WorkVisibility shows them:
 * a team member does not see a project open only to the people it is shared
 * with, and a private task stays not found, in the lists, the reads, the tools,
 * the event feed and the webhooks alike.
 */
final class WorkVisibilityApiTest extends WorkApiTestCase
{
    public function testAMemberDoesNotSeeAChosenProjectNotSharedWithThem(): void
    {
        $open = $this->project('Open');
        $secret = $this->chosenProject();
        $hidden = $this->task($secret, 'Hidden task');
        $seen = $this->task($open, 'Seen task');

        $this->get('/api/v1/projects');
        self::assertSame([$open->id()], $this->ids());
        $this->get('/api/v1/tasks');
        self::assertSame([$seen], $this->ids());
        foreach (['/api/v1/projects/'.$secret->id(), '/api/v1/projects/'.$secret->id().'/people', '/api/v1/tasks/'.$hidden, '/api/v1/tasks/'.$hidden.'/comments'] as $path) {
            $this->get($path);
            $this->assertProblem(404, 'not_found');
        }
        $this->get('/api/v1/tasks', ['project_id' => $secret->id()]);
        self::assertSame([], $this->ids());

        self::assertSame([$open->id()], self::itemIds($this->call(SearchProjectsTool::class)));
        self::assertSame([$seen], self::itemIds($this->call(SearchTasksTool::class)));

        // The owner sees it all.
        $owner = $this->ownerToken();
        $this->get('/api/v1/projects', secret: $owner);
        self::assertSame([$secret->id(), $open->id()], $this->ids());
    }

    public function testAPrivateTaskStaysNotFound(): void
    {
        $project = $this->project();
        $private = $this->privateTask($project, 'Salary review');

        $this->get('/api/v1/tasks');
        self::assertSame([], $this->ids());
        $this->get('/api/v1/tasks/'.$private);
        $this->assertProblem(404, 'not_found');
        $this->assertRefused('not_found', 'No such project, section, status, task, label, comment or time entry, or not one the person sees.', GetTaskTool::class, ['task_id' => $private]);

        // Nor can it be changed or commented on.
        $this->send('PATCH', '/api/v1/tasks/'.$private, ['title' => 'Mine now']);
        $this->assertProblem(404, 'not_found');
        $this->send('POST', '/api/v1/tasks/'.$private.'/comments', ['body' => 'Hello']);
        $this->assertProblem(404, 'not_found');

        $this->get('/api/v1/tasks/'.$private, secret: $this->ownerToken());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'private'));
    }

    public function testTheEventFeedShowsOnlyTheEventsOfTasksThePersonSees(): void
    {
        $project = $this->project();
        $seen = $this->task($project, 'Seen');
        $private = $this->privateTask($project, 'Private');
        $hidden = $this->task($this->chosenProject(), 'In a closed project');
        $this->comment($private, 'About the private one');
        $this->relay();

        $this->get('/api/v1/events', ['types' => 'work.*']);
        self::assertResponseIsSuccessful();
        self::assertSame([$seen], $this->subjects());
        self::assertSame(['work.task.created'], $this->column('type', 'data'));
        self::assertSame('/api/v1/tasks/'.$seen, $this->at('data', 0, 'subject', 'url'));
        self::assertSame(['task_id' => $seen, 'project_id' => $project->id(), 'parent_id' => null], $this->at('data', 0, 'data'));

        $this->get('/api/v1/events', ['types' => 'work.*'], $this->ownerToken());
        $subjects = $this->subjects();
        self::assertContains($private, $subjects);
        self::assertContains($hidden, $subjects);
        self::assertContains('work.comment.added', $this->column('type', 'data'));
    }

    public function testAWebhookOfAMemberIsNotSentTheEventsOfTasksTheyDoNotSee(): void
    {
        $endpoint = $this->flushed(function (): string {
            $endpoint = WebhookEndpoint::create(Id::generate(), new EndpointUrl('https://hooks.example.com/work'), null, EventTypes::of(['work.*']), 1, 'sealed', $this->user->id(), new \DateTimeImmutable('2026-11-19 10:00:00'));
            $this->em()->persist($endpoint);

            return $endpoint->id();
        });
        $project = $this->project();
        $seen = $this->task($project, 'Seen');
        $this->privateTask($project, 'Private');
        $this->relay();

        $db = self::getContainer()->get(Connection::class);
        $bodies = $db->fetchFirstColumn('SELECT body FROM webhook_delivery WHERE endpoint_id = ?', [$endpoint]);
        $subjects = [];
        foreach ($bodies as $body) {
            $event = json_decode(\is_string($body) ? $body : '', true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($event);
            self::assertIsArray($event['subject'] ?? null);
            self::assertIsString($event['subject']['id'] ?? null);
            $subjects[$event['subject']['id']] = true;
        }
        self::assertSame([$seen], array_keys($subjects));
        // The endpoint stays on: its owner still reads the events of Work.
        self::assertTrue((bool) $db->fetchOne('SELECT active FROM webhook_endpoint WHERE id = ?', [$endpoint]));
    }

    private function ownerToken(): string
    {
        return $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->owner->id(), 'Owner', null)->secret);
    }

    private function relay(): void
    {
        self::getContainer()->get(EventRelay::class)->relayAll();
    }
}
