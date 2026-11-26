<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\ProposeSuggestions;
use Foresi\Inbox\Application\ProposingClient;
use Foresi\Inbox\Application\StepRunner;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Party\Contract\ContactCreation;
use Foresi\Party\Contract\NewContact;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\McpToolCalls;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Domain\Task;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The step's "done when", end to end with the real actions: an agent whose MCP
 * token has only inbox:read and inbox_suggestions:write proposes "create a task
 * and log an activity"; the owner opens the activity step's dialog in the
 * browser, changes its subject, saves and accepts; both records exist, made
 * by the owner under the suggestion, and the trail holds both inputs. And the
 * card marks a value the mail does not offer, so the owner sees what a step would
 * act on.
 */
final class InboxSuggestionsDoneWhenTest extends InboxScreenTestCase
{
    use McpToolCalls;

    public function testAnAgentProposesATaskAndAnActivityAndTheOwnerAcceptsWithAnEdit(): void
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser($this->owner->id(), $this->owner->email(), 'hash'), 'main', ['ROLE_USER']));
        $project = self::getContainer()->get(ManageProjects::class)->create('Office move', null);
        $call = new ActivityType('call', null, ActivityTiming::None, null, new \DateTimeImmutable('2026-11-25 10:00:00'));
        $this->em->persist($call);
        $secret = self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->owner->id(), 'Mail agent', [ApiScope::from('inbox:read'), ApiScope::from('inbox_suggestions:write')])->secret;
        $this->em->flush();
        $tokens->setToken(null);
        $itemId = $this->receive(InboxMail::plain($this->address, body: "Hello,\r\nplease call me back about the offer.\r\n"));

        $proposed = $this->callTool($secret, 'foresi_propose_inbox_suggestions', ['item_id' => $itemId, 'suggestions' => [[
            'steps' => [
                ['operation' => 'inbox.actions.create_task', 'input' => ['project_id' => $project->id(), 'title' => 'Answer the offer']],
                ['operation' => 'inbox.actions.log_activity', 'input' => ['type_id' => $call->id(), 'subject' => 'Call about the offer', 'done' => true]],
            ],
            'rationale' => 'The client asks to be called back.',
            'quote' => 'please call me back about the offer',
        ]]]);
        self::assertFalse($proposed['isError'] ?? true, (string) json_encode($proposed));
        $suggestionId = $proposed;
        foreach (['structuredContent', 'data', 'suggestions', 0, 'id'] as $key) {
            self::assertIsArray($suggestionId);
            $suggestionId = $suggestionId[$key] ?? null;
        }
        self::assertIsString($suggestionId);

        $crawler = $this->client->request('GET', '/inbox/'.$itemId.'/suggestions/'.$suggestionId.'/steps/2/edit', server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame('Call about the offer', $crawler->filter('#inbox-action-subject')->attr('value'));
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['subject'] = 'Call Client A about the offer';
        $this->client->submit($form);
        self::assertResponseRedirects('/inbox/'.$itemId);

        $this->em->clear();
        $suggestion = $this->em->find(Suggestion::class, $suggestionId);
        self::assertInstanceOf(Suggestion::class, $suggestion);
        self::assertSame([null, null], [$suggestion->step(1)->refusal(), $suggestion->step(2)->refusal()]);
        self::assertSame(SuggestionState::Accepted, $suggestion->state());
        $item = $this->em->find(InboxItem::class, $itemId);
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(ItemState::Done, $item->state());

        $task = $this->em->find(Task::class, $suggestion->step(1)->recordId());
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Answer the offer', $task->title());
        $activity = $this->em->find(Activity::class, $suggestion->step(2)->recordId());
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame('Call Client A about the offer', $activity->subject());

        $audit = $this->em->getRepository(AuditEntry::class);
        foreach ([[Task::class, $task->id()], [Activity::class, $activity->id()]] as [$class, $id]) {
            $entries = $audit->findBy(['entityClass' => $class, 'entityId' => $id]);
            self::assertNotSame([], $entries, $class);
            foreach ($entries as $entry) {
                self::assertSame([StepRunner::correlation($suggestionId), $this->owner->id()], [$entry->correlationId(), $entry->userId()], $class);
            }
        }
        $changes = array_map(static fn (AuditEntry $entry): array => $entry->changes(), $audit->findBy(['entityClass' => SuggestionStep::class, 'entityId' => $suggestion->step(2)->id()]));
        self::assertTrue(array_any($changes, static fn (array $change): bool => str_contains((string) json_encode($change['proposedInput'] ?? null), 'Call about the offer')), 'The trail holds the proposed input.');
        self::assertTrue(array_any($changes, static fn (array $change): bool => str_contains((string) json_encode($change['finalInput'] ?? null), 'Call Client A about the offer')), 'The trail holds the final input.');
    }

    public function testACardMarksAProjectTheMailDoesNotOffer(): void
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser($this->owner->id(), $this->owner->email(), 'hash'), 'main', ['ROLE_USER']));
        $client = self::getContainer()->get(ContactCreation::class)->create(new NewContact('person', 'Client A', 'Example Street 2', 'Example City', '110 00', 'CZ', '', email: 'client.a@client-a.example.com'));
        $offered = self::getContainer()->get(ManageProjects::class)->create('Office move', $client);
        $other = self::getContainer()->get(ManageProjects::class)->create('Another client\'s project', null);
        $itemId = $this->receive(InboxMail::plain($this->address));
        self::getContainer()->get(ProposeSuggestions::class)->propose($itemId, [[
            'steps' => [
                ['operation' => 'inbox.actions.create_task', 'input' => ['project_id' => $offered->id(), 'title' => 'On the offered project']],
                ['operation' => 'inbox.actions.create_task', 'input' => ['project_id' => $other->id(), 'title' => 'On another project']],
            ],
            'rationale' => 'The client asks for it.',
            'quote' => 'please find our offer',
        ]], new ProposingClient(Id::generate(), 'Mail agent'));
        $tokens->setToken(null);

        $crawler = $this->client->request('GET', '/inbox/'.$itemId);

        self::assertResponseIsSuccessful();
        $steps = $crawler->filter('[data-inbox-suggestion-step]');
        self::assertStringContainsString('Office move', $steps->eq(0)->text());
        self::assertStringNotContainsString('not one this mail offers', $steps->eq(0)->text());
        self::assertStringContainsString($other->id().' (not one this mail offers)', $steps->eq(1)->text(), 'An id outside the choices is marked, not shown as if offered.');
    }
}
