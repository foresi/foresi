<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Integration\Inbox;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Inbox\Support\ContainerPreFills;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\ItemViews;
use Foresi\Tests\Inbox\Support\McpToolCalls;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The inbox action of Activities: an activity logged from an inbox item, linked
 * to the records of the e-mail activity and recorded as an output of the item.
 */
final class ActivitiesInboxActionsTest extends ApiTestCase
{
    use ContainerPreFills;
    use ItemViews;
    use McpToolCalls;

    private string $address;
    private string $agent;
    private string $callTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->callTypeId = $this->flushed(function (): string {
            // The type the activities migration seeds, which a schema built from the mapping lacks.
            $this->em()->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable()));
            $call = new ActivityType('call', 'Call', ActivityTiming::Timed, 15, new \DateTimeImmutable());
            $this->em()->persist($call);

            return $call->id();
        });
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->user->id(), 'My inbox')->address;
        $this->agent = $this->issued([ApiScope::from('inbox:write'), ApiScope::from('activities:write')]);
    }

    public function testLogActivityLinksTheNewActivityToTheSameRecordsAsTheEmailActivityAndStoresTheOutput(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);
        // The first output makes the one e-mail activity, linked to the contact.
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/outputs', ['kind' => 'contact', 'id' => $this->customerId], $this->agent);
        self::assertResponseStatusCodeSame(201);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/activities', ['type_id' => $this->callTypeId, 'subject' => 'Call about the offer', 'done' => true], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $activityId = $this->at('data', 'id');
        self::assertIsString($activityId);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        $emailActivityId = $this->at('data', 'email_activity_id');
        self::assertIsString($emailActivityId);
        self::assertNotSame($activityId, $emailActivityId);
        $outputs = array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs'));
        self::assertEqualsCanonicalizing([['contact', $this->customerId], ['activity', $activityId]], $outputs);
        self::assertSame($this->linksOf($emailActivityId), $this->linksOf($activityId));
        self::assertSame([['contact', $this->customerId]], $this->linksOf($activityId));
    }

    public function testLogActivityRunsOverMcp(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $call = $this->callTool($this->agent, 'foresi_log_activity_from_email', ['inbox_item_id' => $itemId, 'type_id' => $this->callTypeId, 'subject' => 'Call about the offer', 'done' => true]);

        self::assertFalse($call['isError'] ?? null);
        $activityId = ((array) ((array) ($call['structuredContent'] ?? []))['data'])['id'] ?? null;
        self::assertIsString($activityId);
        self::assertSame([['contact', $this->customerId]], $this->linksOf($activityId));
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertContains(['activity', $activityId], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
    }

    public function testMarkDoneCanBeUntickedAndTheContactOfTheItemIsLinked(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/activities', ['type_id' => $this->callTypeId, 'subject' => 'Call about the offer', 'done' => true, 'mark_done' => false], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $activityId = $this->at('data', 'id');
        self::assertIsString($activityId);

        self::assertSame([['contact', $this->customerId]], $this->linksOf($activityId));
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('new', $this->at('data', 'state'));
    }

    public function testAnUnknownTypeIsRefusedAndLeavesNoOutputAndTheItemNew(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/activities', ['type_id' => '0192f1a4-0000-7000-8000-000000000000', 'subject' => 'Call about the offer'], $this->agent);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->at('data', 'outputs'), $this->at('data', 'email_activity_id')]);
    }

    public function testThePreFillHasTheSubjectAndTheTypesWithNoDefaultWhenThereAreSeveral(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, subject: str_repeat('s', 250)));

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        $action = null;
        foreach ((array) $this->at('data', 'available_actions') as $candidate) {
            if (\is_array($candidate) && 'inbox.actions.log_activity' === $candidate['operation']) {
                $action = $candidate;
            }
        }
        self::assertIsArray($action);
        self::assertSame('Log activity', $action['label']);
        self::assertSame(['inbox_item_id' => $itemId, 'subject' => str_repeat('s', 200), 'done' => false, 'mark_done' => true], $action['prefill']);
        $all = $action['choices'] ?? null;
        self::assertIsArray($all);
        $choices = $all['type_id'] ?? null;
        self::assertIsArray($choices);
        self::assertSame('Call', $choices[$this->callTypeId] ?? null);
        self::assertCount(2, $choices);
    }

    public function testTheActivityIsLinkedToWhatCameOfTheItemAndNotToWhatActivitiesCannotLink(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);
        $other = $this->flushed(function () use ($itemId): string {
            $party = new Party(PartyKind::Person, 'Other Person', null, null, '');
            $this->em()->persist($party);
            $item = $this->em()->find(InboxItem::class, $itemId);
            self::assertInstanceOf(InboxItem::class, $item);
            // The record of a module whose records activities cannot be about comes first, then a contact.
            $this->em()->persist(new ItemOutput($item, 'time_entry', '0192f0a0-0000-7000-8000-000000000003', new \DateTimeImmutable('2026-11-24 10:00:00'), $this->user->id()));
            $this->em()->persist(new ItemOutput($item, 'contact', $party->id(), new \DateTimeImmutable('2026-11-24 10:05:00'), $this->user->id()));

            return $party->id();
        });

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/activities', ['type_id' => $this->callTypeId, 'subject' => 'Call about the offer', 'done' => true], $this->agent);

        self::assertResponseStatusCodeSame(201);
        $activityId = $this->at('data', 'id');
        self::assertIsString($activityId);
        self::assertSame([['contact', $this->customerId], ['contact', $other]], $this->sorted($this->linksOf($activityId)));
    }

    public function testAnActivityIsPlannedUnlessItIsSaidToBeDone(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/activities', ['type_id' => $this->callTypeId, 'subject' => 'Call about the offer', 'planned_at' => '2026-12-01T10:00:00+01:00'], $this->agent);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->at('data', 'done'));
        self::assertNull($this->at('data', 'outcome'));
    }

    public function testThePreFillTakesTheSubjectTrimmedAndCut(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(SecurityUser::fromUser($this->user), 'main', ['ROLE_USER']));
        $values = $this->prefillOf('inbox.actions.log_activity', $this->itemView(subject: '  '.str_repeat('ž', 220).'  '))->values;

        self::assertSame(str_repeat('ž', 200), $values['subject']);
        self::assertFalse($values['done']);
        self::assertTrue($values['markDone']);
    }

    /**
     * @param list<array{string, string}> $links
     *
     * @return list<array{string, string}>
     */
    private function sorted(array $links): array
    {
        usort($links, static fn (array $a, array $b): int => $a <=> $b);

        return $links;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body, string $secret): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{string, string}> the kind and id of what the activity is linked to
     */
    private function linksOf(string $activityId): array
    {
        $links = [];
        foreach ($this->em()->getConnection()->fetchAllAssociative('SELECT kind, target_id FROM activities_activity_link WHERE activity_id = ? ORDER BY kind', [$activityId]) as $link) {
            self::assertIsString($link['kind']);
            self::assertIsString($link['target_id']);
            $links[] = [$link['kind'], $link['target_id']];
        }

        return $links;
    }

    private function match(string $itemId): void
    {
        $this->send('PUT', '/api/v1/inbox/items/'.$itemId.'/contact', ['contact_id' => $this->customerId], $this->agent);
        self::assertResponseIsSuccessful();
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
}
