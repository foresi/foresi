<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Inbox;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Party;
use Foresi\Sales\Integration\Inbox\OpenDealSuggester;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The inbox action of Sales: a deal opened from an inbox item with the client
 * of the mail, recorded as an output of the item and linked to the one e-mail
 * activity; the one open deal of a client pre-selected.
 */
final class SalesInboxActionsTest extends ApiTestCase
{
    private string $address;
    private string $agent;
    private string $stageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushed(function (): void {
            // The type the activities migration seeds, which a schema built from the mapping lacks.
            $this->em()->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable()));
        });
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->user->id(), 'My inbox')->address;
        $this->agent = $this->issued([ApiScope::from('inbox:write'), ApiScope::from('sales:write')]);
        $this->send('POST', '/api/v1/sales/stages', ['name' => 'Inquiry'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $this->stageId = $this->idOfAnswer();
    }

    public function testCreateDealFromEmailLinksTheDealToTheOneEmailActivityAndIsAnOutput(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/deals', ['title' => 'Office offer', 'client_party_id' => $this->customerId], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $dealId = $this->idOfAnswer();
        self::assertSame([$this->customerId, $this->stageId], [$this->at('data', 'client', 'id'), $this->at('data', 'stage', 'id')]);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        $output = ((array) $this->at('data', 'outputs'))[0] ?? null;
        self::assertIsArray($output);
        self::assertSame(['deal', $dealId, true, 'Office offer'], [$output['kind'], $output['id'], $output['available'], $output['label']]);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertEqualsCanonicalizing([['contact', $this->customerId], ['deal', $dealId]], $this->linksOf($activityId));
    }

    public function testCreateDealIsNotOfferedForAnUnmatchedItem(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));

        self::assertNotContains('inbox.actions.create_deal', array_keys($this->actions($itemId)));
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/deals', ['title' => 'Office offer', 'client_party_id' => $this->customerId], $this->agent);
        self::assertResponseStatusCodeSame(409);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->at('data', 'outputs'), $this->at('data', 'email_activity_id')]);
    }

    public function testCreateDealIsOfferedForAMatchedItemWithTheSubjectAndTheClient(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, subject: str_repeat('s', 250)));
        $this->match($itemId);

        $deal = $this->actions($itemId)['inbox.actions.create_deal'] ?? null;
        self::assertIsArray($deal);
        self::assertSame('Create deal', $deal['label']);
        self::assertSame(['inbox_item_id' => $itemId, 'title' => str_repeat('s', 200), 'mark_done' => true, 'client_party_id' => $this->customerId], $deal['prefill']);
        $clients = $deal['choices']['client_party_id'] ?? null;
        self::assertIsArray($clients);
        self::assertSame([$this->customerId], array_keys($clients), 'The client is picked by name.');
    }

    public function testARefusedCreateLeavesNoOutputAndTheItemNew(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);
        $this->flushed(function (): void {
            $client = $this->em()->find(Party::class, $this->customerId);
            self::assertInstanceOf(Party::class, $client);
            $client->archive(new \DateTimeImmutable());
        });

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/deals', ['title' => 'Office offer', 'client_party_id' => $this->customerId], $this->agent);
        self::assertResponseStatusCodeSame(409);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->at('data', 'outputs'), $this->at('data', 'email_activity_id')]);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM sales_deal'), 'No deal was opened.');
    }

    public function testTheSingleOpenDealIsPreselected(): void
    {
        $deal = $this->deal('Roof audit');
        $this->wonDeal('Won deal');
        $this->lostDeal('Lost deal');
        $this->trashedDeal('Trashed deal');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $suggested = $this->asUser(fn () => self::getContainer()->get(InboxItems::class)->suggestedRecord($itemId, 'deal'));
        self::assertSame(['deal', $deal], [$suggested?->kind, $suggested?->id]);
    }

    public function testTwoOpenDealsPreselectNone(): void
    {
        $this->deal('Roof audit');
        $this->deal('Window audit');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        self::assertNull($this->asUser(fn () => self::getContainer()->get(InboxItems::class)->suggestedRecord($itemId, 'deal')));
    }

    public function testADealOfAnotherClientIsNotProposed(): void
    {
        $other = $this->flushed(function (): string {
            $party = new Party(\Foresi\Party\Domain\PartyKind::Person, 'Other Person', null, null, '');
            $this->em()->persist($party);

            return $party->id();
        });
        $this->send('POST', '/api/v1/sales/deals', ['title' => 'Other deal', 'client_party_id' => $other], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        self::assertNull($this->asUser(fn () => self::getContainer()->get(InboxItems::class)->suggestedRecord($itemId, 'deal')));
    }

    public function testAPersonWhoCannotSeeDealsIsProposedNone(): void
    {
        $this->deal('Roof audit');
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);

        $suggested = $this->asUser(fn () => self::getContainer()->get(OpenDealSuggester::class)->suggest($this->customerId, null), $guest);
        self::assertNull($suggested);
    }

    private function deal(string $title): string
    {
        $this->send('POST', '/api/v1/sales/deals', ['title' => $title, 'client_party_id' => $this->customerId], $this->agent);
        self::assertResponseStatusCodeSame(201);

        return $this->idOfAnswer();
    }

    private function wonDeal(string $title): void
    {
        $this->send('POST', '/api/v1/sales/deals/'.$this->deal($title).'/win', [], $this->agent);
        self::assertResponseIsSuccessful();
    }

    private function lostDeal(string $title): void
    {
        $this->send('POST', '/api/v1/sales/deals/'.$this->deal($title).'/lose', [], $this->agent);
        self::assertResponseIsSuccessful();
    }

    private function trashedDeal(string $title): void
    {
        $this->send('POST', '/api/v1/sales/deals/'.$this->deal($title).'/trash', [], $this->agent);
        self::assertResponseIsSuccessful();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function asUser(\Closure $work, ?\Foresi\Identity\Domain\User $user = null): mixed
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user ?? $this->user), 'main', ['ROLE_USER']));
        try {
            return $work();
        } finally {
            $tokens->setToken(null);
        }
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

    private function idOfAnswer(): string
    {
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, array{label: string, prefill: array<mixed>, choices: array<mixed>}> by operation
     */
    private function actions(string $itemId): array
    {
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertResponseIsSuccessful();
        $actions = [];
        foreach ((array) $this->at('data', 'available_actions') as $action) {
            self::assertIsArray($action);
            self::assertIsString($action['operation']);
            self::assertIsString($action['label']);
            self::assertIsArray($action['prefill']);
            $choices = $action['choices'] ?? [];
            self::assertIsArray($choices);
            $actions[$action['operation']] = ['label' => $action['label'], 'prefill' => $action['prefill'], 'choices' => $choices];
        }

        return $actions;
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
