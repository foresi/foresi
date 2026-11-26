<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Integration\Inbox;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The inbox action of Activities: an activity logged from an inbox item, linked
 * to the records of the e-mail activity and recorded as an output of the item.
 */
final class ActivitiesInboxActionsTest extends ApiTestCase
{
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
