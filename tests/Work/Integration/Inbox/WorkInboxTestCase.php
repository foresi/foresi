<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

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
use Foresi\Tests\Inbox\Support\ContainerPreFills;
use Foresi\Tests\Inbox\Support\ItemViews;
use Foresi\Tests\Inbox\Support\McpToolCalls;
use Foresi\Tests\Inbox\Support\RebuiltServices;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Domain\Project;

/**
 * The actions of an inbox item that make work, over the REST API and MCP: an
 * item of the customer's mail in the inbox of the person signed in, the e-mail
 * activity type the activities migration seeds, and an agent with the scopes
 * of the actions. The tests of Work's own actions and of the actions of the
 * modules built on Work extend it.
 */
abstract class WorkInboxTestCase extends WorkApiTestCase
{
    use ContainerPreFills;
    use ItemViews;
    use McpToolCalls;
    use RebuiltServices;

    protected string $address;
    protected string $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushed(function (): void {
            // The type the activities migration seeds, which a schema built from the mapping lacks.
            $this->em()->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable()));
        });
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->user->id(), 'My inbox')->address;
        $this->agent = $this->issued([ApiScope::from('inbox:write'), ApiScope::from('tasks:write'), ApiScope::from('time_entries:write'), ApiScope::from('projects:write')]);
    }

    /**
     * The project of the client of the mail unless told otherwise.
     */
    protected function project(string $name = 'Website', bool $ofTheClient = true): Project
    {
        return $this->as(fn (): Project => $this->service(ManageProjects::class)->create($name, $ofTheClient ? $this->customerId : null));
    }

    /**
     * @return array<string, array{label: string, prefill: array<mixed>, choices: array<mixed>}> by operation
     */
    protected function actions(string $itemId): array
    {
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertResponseIsSuccessful();
        $actions = [];
        foreach ((array) $this->at('data', 'available_actions') as $action) {
            self::assertIsArray($action);
            self::assertIsString($action['operation']);
            self::assertIsString($action['label']);
            self::assertIsArray($action['prefill']);
            self::assertIsArray($action['choices'] ?? []);
            $actions[$action['operation']] = ['label' => $action['label'], 'prefill' => $action['prefill'], 'choices' => $action['choices'] ?? []];
        }

        return $actions;
    }

    /**
     * @return list<array{string, string}> the kind and id of what the activity is linked to
     */
    protected function linksOf(string $activityId): array
    {
        $links = [];
        foreach ($this->em()->getConnection()->fetchAllAssociative('SELECT kind, target_id FROM activities_activity_link WHERE activity_id = ? ORDER BY kind', [$activityId]) as $link) {
            self::assertIsString($link['kind']);
            self::assertIsString($link['target_id']);
            $links[] = [$link['kind'], $link['target_id']];
        }

        return $links;
    }

    protected function setEmailTypeArchived(bool $archived): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE activities_type SET archived_at = ? WHERE type_key = ?', [$archived ? '2026-11-24 10:00:00' : null, 'email']);
        $this->em()->clear();
    }

    protected function match(string $itemId): void
    {
        $this->send('PUT', '/api/v1/inbox/items/'.$itemId.'/contact', ['contact_id' => $this->customerId], $this->agent);
        self::assertResponseIsSuccessful();
    }

    protected function receive(string $raw): string
    {
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook, $this->address));
        $messageId = $this->em()->getConnection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]);
        self::assertIsString($messageId);
        $item = new DoctrineItemStore($this->em())->itemOfMessage($messageId);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item->id();
    }
}
