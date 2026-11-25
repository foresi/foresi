<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Infrastructure\Api;

use Doctrine\DBAL\Connection;
use Foresi\Events\Infrastructure\Api\PositionCursor;
use Foresi\Events\Infrastructure\Relay\EventRelay;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Events\Support\RecordedEvents;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * GET /api/v1/events: the events that have a position, from the last thirty
 * days, in the order of their positions.
 */
final class EventApiControllerTest extends ApiTestCase
{
    private RecordedEvents $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new RecordedEvents(
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(EventRecorder::class),
            self::getContainer()->get(EventRelay::class),
        );
    }

    public function testListsAscendingByPosition(): void
    {
        $contact = Id::generate();
        $this->events->record(RecordedEvents::created($contact));
        $this->events->record(RecordedEvents::updated($contact));
        $this->events->record(RecordedEvents::archived($contact));
        $this->events->number();

        $this->get('/api/v1/events');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $data = $this->at('data');
        self::assertIsArray($data);
        self::assertSame(['party.contact.created', 'party.contact.updated', 'party.contact.archived'], array_column($data, 'type'));
        self::assertSame([1, 2, 3], array_column($data, 'position'));
        self::assertSame(PositionCursor::encode(3), $this->at('next_cursor'));
    }

    public function testCursorContinuesAfterLastPosition(): void
    {
        $ids = [Id::generate(), Id::generate(), Id::generate(), Id::generate(), Id::generate()];
        foreach ($ids as $id) {
            $this->events->record(RecordedEvents::created($id));
        }
        $this->events->number();

        $pages = [];
        $cursor = null;
        do {
            $this->get('/api/v1/events', ['limit' => '2'] + (null === $cursor ? [] : ['cursor' => $cursor]));
            $pages[] = $this->ids();
            $cursor = $this->at('next_cursor');
            self::assertIsString($cursor);
        } while (2 === \count(end($pages)) && \count($pages) < 10);

        self::assertSame([2, 2, 1], array_map(count(...), $pages));
        self::assertSame(PositionCursor::encode(5), $cursor);
        $this->get('/api/v1/events');
        self::assertSame($this->ids(), array_merge(...$pages));

        // Caught up: the cursor stays where it was and nothing is listed.
        $this->get('/api/v1/events', ['cursor' => PositionCursor::encode(5)]);
        self::assertSame([], $this->at('data'));
        self::assertSame(PositionCursor::encode(5), $this->at('next_cursor'));

        // What happens afterwards is listed from that cursor.
        $this->events->record(RecordedEvents::archived($ids[0]));
        $this->events->number();
        $this->get('/api/v1/events', ['cursor' => PositionCursor::encode(5)]);
        self::assertSame([6], array_column($this->dataRows(), 'position'));
    }

    public function testUnpositionedEventsAreNotListed(): void
    {
        $this->events->record(RecordedEvents::created(Id::generate()));

        $this->get('/api/v1/events');

        self::assertSame([], $this->at('data'));
        self::assertNull($this->at('next_cursor'));
    }

    public function testFiltersByTypeAndWildcard(): void
    {
        $contact = Id::generate();
        $this->events->record(RecordedEvents::created($contact));
        $this->events->record(RecordedEvents::updated($contact));
        $this->events->record(RecordedEvents::archived($contact));
        $this->events->record(RecordedEvents::restored($contact));
        $this->events->number();

        $this->get('/api/v1/events', ['types' => 'party.contact.archived']);
        self::assertSame(['party.contact.archived'], array_column($this->dataRows(), 'type'));

        $this->get('/api/v1/events', ['types' => 'party.contact.updated, party.contact.restored']);
        self::assertSame(['party.contact.updated', 'party.contact.restored'], array_column($this->dataRows(), 'type'));

        $this->get('/api/v1/events', ['types' => 'party.*']);
        self::assertCount(4, $this->dataRows());
    }

    public function testUnknownTypeIs400(): void
    {
        foreach (['party.contact.nothing', 'nothing.*', 'party.contact.archived,wrong'] as $types) {
            $this->get('/api/v1/events', ['types' => $types]);

            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'types']);
        }
    }

    public function testBadCursorAndLimitAre400(): void
    {
        $this->get('/api/v1/events', ['cursor' => Id::generate()]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'cursor']);

        foreach (['0', '101'] as $limit) {
            $this->get('/api/v1/events', ['limit' => $limit]);
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'limit']);
        }
    }

    public function testAMemberWhoBecameAGuestHoldsNoValidToken(): void
    {
        // A guest never reaches the feed over REST: tokens of guests are refused before it. The refusal
        // of a module's events to one who is signed in is covered by ReadableEventsTest and the tools.
        $this->flushed(function (): void {
            $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
            $this->em()->persist($party);
            $membership = $this->em()->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('No membership.');
            $membership->changeRole(Role::Guest, $party);
        });

        $this->get('/api/v1/events');

        $this->assertProblem(401, 'invalid_token');
    }

    public function testOlderThanThirtyDaysIsNotListed(): void
    {
        $old = Id::generate();
        $fresh = Id::generate();
        $this->events->record(RecordedEvents::created($old));
        $this->events->record(RecordedEvents::created($fresh));
        $this->events->number();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE event_log SET occurred_at = :at WHERE subject_id = :subject', ['at' => new \DateTimeImmutable('-31 days')->format('Y-m-d H:i:s'), 'subject' => $old]);

        $this->get('/api/v1/events');

        self::assertSame([$fresh], array_column(array_column($this->dataRows(), 'subject'), 'id'));
    }

    public function testEnvelopeShape(): void
    {
        $contact = Id::generate();
        $this->events->record(RecordedEvents::created($contact));
        $this->events->number();

        $this->get('/api/v1/events');

        self::assertSame(['id', 'type', 'version', 'position', 'occurred_at', 'subject', 'data', 'correlation_id', 'actor'], array_keys($this->dataRows()[0]));
        self::assertTrue(Id::isValid($this->text('data', 0, 'id')));
        self::assertSame('party.contact.created', $this->at('data', 0, 'type'));
        self::assertSame(1, $this->at('data', 0, 'version'));
        self::assertSame(1, $this->at('data', 0, 'position'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $this->text('data', 0, 'occurred_at'));
        self::assertSame(['type' => 'contact', 'id' => $contact, 'url' => '/api/v1/contacts/'.$contact], $this->at('data', 0, 'subject'));
        self::assertSame(['kind' => 'system'], $this->at('data', 0, 'actor'));
        self::assertNull($this->at('data', 0, 'correlation_id'));
        self::assertSame(['contact_id' => $contact, 'kind' => 'company'], $this->at('data', 0, 'data'));

        $body = (string) $this->client->getResponse()->getContent();
        foreach (['user_id', 'api_token', $this->user->id()] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
    }

    public function testTheFeedNeedsTheEventsScope(): void
    {
        $this->get('/api/v1/events', secret: $this->issued([ApiScope::from('contacts:write')]));
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/events', secret: $this->issued([ApiScope::from('events:read')]));
        self::assertResponseIsSuccessful();

        $this->get('/api/v1/events', secret: $this->issued([ApiScope::read()]));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/events');
        $this->assertProblem(401, 'missing_token');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dataRows(): array
    {
        self::assertResponseIsSuccessful();
        $data = $this->at('data');
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $rows[] = array_combine(array_map(strval(...), array_keys($row)), $row);
        }

        return $rows;
    }

    private function text(string|int ...$keys): string
    {
        $value = $this->at(...$keys);
        self::assertIsString($value);

        return $value;
    }
}
