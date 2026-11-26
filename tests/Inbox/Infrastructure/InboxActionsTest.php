<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Infrastructure\DependencyInjection\InboxActionPass;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureContactNoteHandler;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNoteInput;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNotes;
use Foresi\Tests\Inbox\Fixtures\Action\HasMatchedContact;
use Foresi\Tests\Inbox\Fixtures\BrokenAction\BadConditionInput;
use Foresi\Tests\Inbox\Fixtures\BrokenAction\NoItemIdHandler;
use Foresi\Tests\Inbox\Fixtures\BrokenAction\NoMarkDoneInput;
use Foresi\Tests\Inbox\Fixtures\BrokenAction\NotAnActionInput;
use Foresi\Tests\Inbox\Fixtures\BrokenAction\WrongPathInput;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The actions modules declare on an inbox item, with the module only the tests
 * have (tests/Inbox/Fixtures/Action): offered on the item as its condition and
 * the token's scopes allow, run through the same runner over REST and MCP, and
 * what they made recorded as outputs of the item.
 */
final class InboxActionsTest extends ApiTestCase
{
    private const string NOTE = 'inbox.actions.fixture_note';
    private const string CONTACT_NOTE = 'inbox.actions.fixture_contact_note';

    private string $address;
    private string $agent;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushed(function (): void {
            // The type the activities migration seeds, which a schema built from the mapping lacks.
            $this->em()->persist(new ActivityType('email', null, ActivityTiming::None, null, self::getContainer()->get(ClockInterface::class)->now()));
        });
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->user->id(), 'My inbox')->address;
        $this->agent = $this->issued([ApiScope::from('inbox:write'), ApiScope::from('views:write')]);
    }

    public function testAnActionWithoutAnInboxItemIdBreaksTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NoItemIdHandler::class, new Definition(NoItemIdHandler::class))->addTag(OperationHandler::TAG);

        $this->expectExceptionObject(new \LogicException('The inbox action Foresi\Tests\Inbox\Fixtures\BrokenAction\NoItemIdInput must have the field inboxItemId, a string.'));

        new InboxActionPass()->process($container);
    }

    public function testAnActionMustTakeTheInputContractAndAConditionThatIsOne(): void
    {
        foreach ([
            NotAnActionInput::class => 'must implement Foresi\Inbox\Contract\InboxActionInput.',
            WrongPathInput::class => 'must name {inboxItemId}.',
            NoMarkDoneInput::class => 'must have the field markDone, a bool.',
            BadConditionInput::class => 'The condition stdClass of the inbox action',
        ] as $input => $message) {
            try {
                InboxActionPass::check($input);
                self::fail(\sprintf('%s passed.', $input));
            } catch (\LogicException $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
            }
        }
        InboxActionPass::check(FixtureNoteInput::class);
        $this->addToAssertionCount(1);
    }

    public function testAConditionThatIsNoServiceBreaksTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(FixtureContactNoteHandler::class, new Definition(FixtureContactNoteHandler::class))->addTag(OperationHandler::TAG);
        try {
            new InboxActionPass()->process($container);
            self::fail('The build passed.');
        } catch (\LogicException $refused) {
            self::assertStringContainsString('is no service; register it as one.', $refused->getMessage());
        }

        $container->setDefinition(HasMatchedContact::class, new Definition(HasMatchedContact::class))->addTag(InboxActionCondition::TAG);
        new InboxActionPass()->process($container);
        $this->addToAssertionCount(1);
    }

    public function testACustomerModuleActionAppearsWithoutInboxChanges(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertResponseIsSuccessful();
        $actions = $this->at('data', 'available_actions');
        self::assertIsArray($actions);
        self::assertSame([self::NOTE], array_column($actions, 'operation'), 'The contact note needs a matched contact.');
        $note = $actions[0];
        self::assertIsArray($note);
        self::assertSame('foresi_add_fixture_note_from_email', $note['tool']);
        self::assertSame('inbox_fixture.action.note', $note['label']);
        self::assertSame(['inbox_item_id' => $itemId, 'text' => 'Offer for the new office', 'note_kind' => 'mail'], $note['prefill']);
        self::assertSame(['note_kind' => ['mail' => 'Mail', 'call' => 'Call']], $note['choices']);
        $schema = $note['input_schema'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertSame(['inbox_item_id', 'text', 'note_kind', 'fail', 'mark_done', 'fail_recording'], array_keys($schema['properties']));

        $call = $this->tool($this->agent, 'foresi_get_inbox_item', ['item_id' => $itemId]);
        self::assertSame($actions, $this->in($call, 'structuredContent', 'data', 'available_actions'));

        $declared = self::getContainer()->get(InboxActions::class)->get(self::NOTE);
        self::assertNotNull($declared);
        self::assertSame([FixtureNotes::KIND, 50], [$declared->action->kind, $declared->action->weight]);
    }

    public function testAConditionHidesAnAction(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));

        self::assertSame([self::NOTE], $this->offered($itemId));
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-contact-notes', ['text' => 'Call back']);
        $this->assertProblem(409, 'action_not_applicable');
        self::assertSame([], $this->notes()->made);

        $this->send('PUT', '/api/v1/inbox/items/'.$itemId.'/contact', ['contact_id' => $this->customerId]);
        self::assertResponseIsSuccessful();
        self::assertSame([self::NOTE, self::CONTACT_NOTE], $this->offered($itemId));
    }

    public function testAnActionOutsideTheTokensScopesIsNotListed(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $inboxOnly = $this->issued([ApiScope::from('inbox:write')]);

        self::assertSame([], $this->offered($itemId, $inboxOnly));
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note'], $inboxOnly);
        $this->assertProblem(403, 'insufficient_scope');
        self::assertSame([], $this->notes()->made);
    }

    public function testAnActionNeedsTheInboxReadScopeToo(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $fixtureScopeOnly = $this->issued([ApiScope::from('views:write')]);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note'], $fixtureScopeOnly);
        $this->assertProblem(403, 'insufficient_scope');
        self::assertSame('The token does not have the "inbox:read" scope.', $this->at('detail'));
        $call = $this->tool($fixtureScopeOnly, 'foresi_add_fixture_note_from_email', ['inbox_item_id' => $itemId, 'text' => 'A note']);
        self::assertSame('insufficient_scope', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame([], $this->notes()->made, 'Nothing is made.');
    }

    public function testMarkDoneIsTheDefaultAndCanBeUnticked(): void
    {
        $done = $this->receive(InboxMail::plain($this->address));
        $kept = $this->receive(InboxMail::plain($this->address, subject: 'Another offer', messageId: 'offer-9@client-a.example.com'));

        $this->send('POST', '/api/v1/inbox/items/'.$done.'/fixture-notes', ['text' => 'A note']);
        self::assertResponseStatusCodeSame(201);
        $noteId = $this->at('data', 'id');
        $this->send('POST', '/api/v1/inbox/items/'.$kept.'/fixture-notes', ['text' => 'Another note', 'mark_done' => false]);
        self::assertResponseStatusCodeSame(201);

        $this->get('/api/v1/inbox/items/'.$done, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([[FixtureNotes::KIND, $noteId, false]], array_map(static fn (array $output): array => [$output['kind'], $output['id'], $output['available']], $this->outputs()));
        $this->get('/api/v1/inbox/items/'.$kept, secret: $this->agent);
        self::assertSame('new', $this->at('data', 'state'));
        self::assertCount(1, $this->outputs());
        self::assertSame([['item_id' => $done]], $this->eventData('inbox.item.done'));
        self::assertSame([['item_id' => $done, 'kind' => FixtureNotes::KIND], ['item_id' => $kept, 'kind' => FixtureNotes::KIND]], $this->eventData('inbox.item.linked'));
    }

    public function testAnActionOnAnotherPersonsItemIsRefusedAsNotFound(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $other = $this->fixtures->user('other@example.com');
        $this->fixtures->join($other, Role::Member);
        $secret = $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($other->id(), 'Agent', [ApiScope::from('inbox:write'), ApiScope::from('views:write')])->secret);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note'], $secret);
        $this->assertProblem(404, 'not_found');
        $call = $this->tool($secret, 'foresi_add_fixture_note_from_email', ['inbox_item_id' => $itemId, 'text' => 'A note']);
        self::assertSame('not_found', $this->in($call, 'structuredContent', 'error', 'code'));

        self::assertSame([], $this->notes()->made, 'Nothing is made.');
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', []], [$this->at('data', 'state'), $this->outputs()]);
    }

    public function testAnActionOnAnItemInTheTrashIsRefused(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->at('data', 'available_actions'), 'Nothing is offered in the trash.');

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note']);
        $this->assertProblem(409, 'item_trashed');
        self::assertSame([], $this->notes()->made);
    }

    public function testAFailingActionLeavesNoOutputAndTheItemNew(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note', 'fail' => true]);
        $this->assertProblem(409, 'fixture_failed');

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->outputs(), $this->at('data', 'email_activity_id')]);
        self::assertSame([], $this->eventData('inbox.item.linked'));
    }

    public function testTwoActionsOnOneItemGiveTwoOutputsAndOneActivity(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->send('PUT', '/api/v1/inbox/items/'.$itemId.'/contact', ['contact_id' => $this->customerId]);
        self::assertResponseIsSuccessful();

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note', 'mark_done' => false]);
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        $call = $this->tool($this->agent, 'foresi_add_fixture_contact_note_from_email', ['inbox_item_id' => $itemId, 'text' => 'Call back', 'contact_id' => $this->customerId]);
        self::assertFalse($this->in($call, 'isError'));

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame($activityId, $this->at('data', 'email_activity_id'), 'One e-mail activity, extended.');
        self::assertSame(array_keys($this->notes()->made), array_column($this->outputs(), 'id'));
        self::assertSame([$itemId], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM inbox_item WHERE email_activity_id = ?', [$activityId]));
    }

    public function testAFailureWhileRecordingRollsBackTheOutputKeepsTheRecordAndSaysSo(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A note', 'fail_recording' => true]);
        self::assertResponseStatusCodeSame(201, 'The note was made and committed.');
        $call = $this->tool($this->agent, 'foresi_add_fixture_note_from_email', ['inbox_item_id' => $itemId, 'text' => 'Another note', 'fail_recording' => true]);
        self::assertFalse($this->in($call, 'isError'));
        $text = $this->in($call, 'content', 0, 'text');
        self::assertIsString($text);
        self::assertStringContainsString('Made the note. '.RecordOutputs::NOT_LINKED, $text);

        self::assertSame(['A note', 'Another note'], array_values($this->notes()->made), 'Both records survive.');
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertResponseIsSuccessful();
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->outputs(), $this->at('data', 'email_activity_id')], 'The output and the e-mail activity were rolled back.');
        self::assertSame([], $this->eventData('inbox.item.linked'));

        // The next action on the same kernel records as usual.
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/fixture-notes', ['text' => 'A third note']);
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertCount(1, $this->outputs());
    }

    public function testASecondActionExtendsTheActivityOfTheFirstThoughItReadTheItemBefore(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->signInAsOwner();
        $attribute = InboxActions::attributeOf(FixtureNoteInput::class);
        self::assertInstanceOf(InboxAction::class, $attribute);
        $outputs = self::getContainer()->get(RecordOutputs::class);
        $connection = $this->em()->getConnection();

        self::assertTrue($outputs->record($attribute, new FixtureNoteInput($itemId, 'First', markDone: false), new OperationOutput(['id' => $this->notes()->make('First')], 'Made the note.')));
        $first = $connection->fetchOne('SELECT email_activity_id FROM inbox_item WHERE id = ?', [$itemId]);
        self::assertIsString($first);
        // This request read the item before the other one made the activity, as two parallel actions do.
        $connection->executeStatement('UPDATE inbox_item SET email_activity_id = NULL WHERE id = ?', [$itemId]);
        $this->em()->clear();
        self::assertNull(new DoctrineItemStore($this->em())->item($itemId)?->emailActivityId());
        $connection->executeStatement('UPDATE inbox_item SET email_activity_id = ? WHERE id = ?', [$first, $itemId]);

        self::assertTrue($outputs->record($attribute, new FixtureNoteInput($itemId, 'Second', markDone: false), new OperationOutput(['id' => $this->notes()->make('Second')], 'Made the note.')));

        self::assertSame($first, $connection->fetchOne('SELECT email_activity_id FROM inbox_item WHERE id = ?', [$itemId]), 'The lock read the item again: one e-mail activity.');
        self::assertCount(2, $this->storedOutputs($itemId));
    }

    public function testTheActionRunsOverMcpThroughTheSameRunner(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $call = $this->tool($this->agent, 'foresi_add_fixture_note_from_email', ['inbox_item_id' => $itemId, 'text' => 'A note']);

        self::assertFalse($this->in($call, 'isError'));
        $noteId = $this->in($call, 'structuredContent', 'data', 'id');
        self::assertIsString($noteId);
        self::assertSame([$noteId => 'A note'], $this->notes()->made);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['done', [$noteId]], [$this->at('data', 'state'), array_column($this->outputs(), 'id')]);
    }

    public function testARecordThatCannotBeAddedIsAnsweredFalseAndLeavesTheItemAsItWas(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->signInAsOwner();
        $attribute = InboxActions::attributeOf(FixtureNoteInput::class);
        self::assertInstanceOf(InboxAction::class, $attribute);
        $outputs = self::getContainer()->get(RecordOutputs::class);
        $input = new FixtureNoteInput($itemId, 'A note', markDone: false);

        self::assertFalse($outputs->record($attribute, $input, new OperationOutput(['text' => 'A note'], 'Made the note.')), 'An answer without an id.');
        self::assertSame([], $this->storedOutputs($itemId));
        $noteId = $this->notes()->make('A note');
        self::assertTrue($outputs->record($attribute, $input, new OperationOutput(['id' => $noteId], 'Made the note.')));
        self::assertTrue($outputs->record($attribute, $input, new OperationOutput(['id' => $noteId], 'Made the note.')), 'The same record twice is one output.');
        self::assertSame([$noteId], $this->storedOutputs($itemId));
    }

    private function signInAsOwner(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($this->user->id(), $this->user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    /**
     * @return list<string> the record ids of the item's outputs
     */
    private function storedOutputs(string $itemId): array
    {
        $this->em()->clear();
        $store = new DoctrineItemStore($this->em());
        $item = $store->item($itemId);
        self::assertInstanceOf(InboxItem::class, $item);

        return array_map(static fn (ItemOutput $output): string => $output->recordId(), $store->outputs($item));
    }

    /**
     * @return list<string> the operations of the item's available actions
     */
    private function offered(string $itemId, ?string $secret = null): array
    {
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $secret ?? $this->agent);
        self::assertResponseIsSuccessful();
        $actions = $this->at('data', 'available_actions');
        self::assertIsArray($actions);

        return array_values(array_map(static fn (mixed $action): string => \is_array($action) && \is_string($action['operation'] ?? null) ? $action['operation'] : '', $actions));
    }

    private function notes(): FixtureNotes
    {
        $notes = self::getContainer()->get(FixtureNotes::class); // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only, and PHPStan reads the dev container)
        self::assertInstanceOf(FixtureNotes::class, $notes);

        return $notes;
    }

    private function receive(string $raw): string
    {
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook, $this->address));
        $messageId = $this->em()->getConnection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]);
        self::assertIsString($messageId);
        $item = new DoctrineItemStore($this->em())->itemOfMessage($messageId);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item->id();
    }

    /**
     * @return list<array<mixed>>
     */
    private function outputs(): array
    {
        $outputs = [];
        foreach ((array) $this->at('data', 'outputs') as $output) {
            self::assertIsArray($output);
            $outputs[] = $output;
        }

        return $outputs;
    }

    /**
     * @return list<array<mixed>> the decoded data of the events of the name, oldest first
     */
    private function eventData(string $name): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);
        $events = [];
        foreach ($rows as $data) {
            self::assertIsString($data);
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->agent), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $this->client->request($method, $path, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $secret, string $name, array $arguments): array
    {
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $result = $this->in($this->mcp($secret, 'tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $secret, string $method, array $params): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->session ??= $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function in(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
