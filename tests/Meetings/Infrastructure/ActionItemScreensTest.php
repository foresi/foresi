<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Infrastructure;

use Foresi\Meetings\Domain\ActionItem;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\TaskReferenceSyntax;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Create task from the minutes on the screens: the dialog prefilled from the
 * selection, Create answering the editor once per key, the preview and the
 * meeting's page with the pills, the action items and the carry-over, and the
 * task's page linking back to its meeting.
 */
final class ActionItemScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->browser->seed();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
        $this->browser->signIn();
    }

    public function testATaskFromTheSelectionShowsOnTheMeetingAndLinksBack(): void
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->request('POST', '/projects/new', ['name' => 'Website'] + $crawler->filter('#project-form')->form()->getPhpValues());
        $project = $this->one(Project::class);
        $crawler = $this->client->request('GET', '/meetings/series/new');
        $this->client->request('POST', '/meetings/series/new', ['name' => 'C-level weekly', 'visibility' => 'members'] + $crawler->filter('#series-form')->form()->getPhpValues());
        $series = $this->one(MeetingSeries::class);
        $crawler = $this->client->request('GET', '/meetings/new', ['series' => $series->id()]);
        $this->client->request('POST', '/meetings/new', ['heldOn' => '2026-11-30'] + $crawler->filter('#meeting-form')->form()->getPhpValues());
        $meeting = $this->one(Meeting::class);

        $crawler = $this->client->request('GET', '/meetings/'.$meeting->id());
        self::assertSame('/meetings/'.$meeting->id().'/action-items/new', $crawler->filter('[data-markdown-editor]')->attr('data-markdown-editor-task-url-value'));
        self::assertCount(1, $crawler->filter('[data-markdown-editor-tool="task"][disabled]'));

        $crawler = $this->client->request('GET', '/meetings/'.$meeting->id().'/action-items/new', ['title' => "  Send the\toffer "]);
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('#action-item-form')->form()->getPhpValues();
        self::assertSame(['Send the offer', $project->id(), '1'], [$values['title'] ?? null, $values['project'] ?? null, $values['private'] ?? null]);

        // A change of project sends the form again: it offers the project's people and makes nothing.
        unset($values['private']);
        $this->client->request('POST', '/meetings/'.$meeting->id().'/action-items/new', $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#action-item-private:checked');
        self::assertSame(0, $this->rows(Task::class));
        $values['private'] = '1';

        $this->client->request('POST', '/meetings/'.$meeting->id().'/action-items/new', ['_action' => 'create', 'due' => '2026-02-30'] + $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/meetings/'.$meeting->id().'/action-items/new', ['_action' => 'create'] + $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertSame('markdown-editor:task-created', $answer['event'] ?? null);
        self::assertIsArray($answer['detail'] ?? null);
        $taskId = $answer['detail']['taskId'] ?? null;
        self::assertIsString($taskId);
        $this->client->request('POST', '/meetings/'.$meeting->id().'/action-items/new', ['_action' => 'create'] + $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);
        $again = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($again);
        self::assertIsArray($again['detail'] ?? null);
        self::assertSame($taskId, $again['detail']['taskId'] ?? null);
        self::assertSame([1, 1], [$this->rows(Task::class), $this->rows(ActionItem::class)]);
        $task = $this->browser->em()->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $task);
        self::assertTrue($task->isPrivate());

        $token = TaskReferenceSyntax::token('Send the offer', $taskId);
        $crawler = $this->client->request('GET', '/meetings/'.$meeting->id());
        $this->client->request('POST', '/meetings/'.$meeting->id().'/minutes/preview', ['text' => '- '.$token, '_token' => $crawler->filter('[data-markdown-editor]')->attr('data-markdown-editor-token-value')]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-task-pill="open"', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/meetings/'.$meeting->id());
        $this->client->request('POST', '/meetings/'.$meeting->id().'/minutes', ['minutes' => '- '.$token, 'version' => $meeting->version(), '_token' => $crawler->filter('[data-meeting-minutes-form] input[name="_token"]')->attr('value')]);
        $crawler = $this->client->request('GET', '/meetings/'.$meeting->id());
        self::assertCount(1, $crawler->filter('[data-meeting-minutes] [data-task-pill="open"]'));
        self::assertSelectorTextContains('[data-meeting-action-items]', 'Send the offer');
        self::assertSelectorTextContains('[data-meeting-carry-over]', 'Nothing is open from earlier meetings.');
        self::assertSelectorTextContains('[data-meeting-series-open]', 'All open tasks of this series (1)');

        $this->client->request('GET', '/tasks/'.$taskId);
        self::assertSelectorTextContains('[data-task-meeting]', 'From meeting: C-level weekly – 2026-11-30');
        $this->client->request('GET', '/meetings/series/'.$series->id().'/open-tasks');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-series-open-tasks]', 'Send the offer');
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        $em = $this->browser->em();
        $em->clear();

        return $em->getRepository($class)->count([]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function one(string $class): object
    {
        $em = $this->browser->em();
        $em->clear();
        $found = $em->getRepository($class)->findOneBy([]);
        self::assertInstanceOf($class, $found);

        return $found;
    }
}
