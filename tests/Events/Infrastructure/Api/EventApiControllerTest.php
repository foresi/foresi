<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Api;

use App\Events\Infrastructure\Api\PositionCursor;
use App\Events\Infrastructure\Relay\EventRelay;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tests\Events\Support\RecordedEvents;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * GET /api/v1/events: the events of the token's organisation that have a
 * position, from the last thirty days, in the order of their positions.
 */
final class EventApiControllerTest extends ApiTestCase
{
    private RecordedEvents $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new RecordedEvents(
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(EventRecorder::class),
            self::getContainer()->get(EventRelay::class),
        );
    }

    public function testListsAscendingByPosition(): void
    {
        $invoice = Id::generate();
        $payment = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::issued($invoice));
        $this->events->record($this->tenant, RecordedEvents::payment($payment, $invoice));
        $this->events->record($this->tenant, RecordedEvents::paid($invoice));
        $this->events->number();

        $this->get('/api/v1/events');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $data = $this->at('data');
        self::assertIsArray($data);
        self::assertSame(['invoicing.invoice.issued', 'invoicing.payment.recorded', 'invoicing.invoice.paid'], array_column($data, 'type'));
        self::assertSame([1, 2, 3], array_column($data, 'position'));
        self::assertSame(PositionCursor::encode(3), $this->at('next_cursor'));
    }

    public function testCursorContinuesAfterLastPosition(): void
    {
        $ids = [Id::generate(), Id::generate(), Id::generate(), Id::generate(), Id::generate()];
        foreach ($ids as $id) {
            $this->events->record($this->tenant, RecordedEvents::issued($id));
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
        $this->events->record($this->tenant, RecordedEvents::paid($ids[0]));
        $this->events->number();
        $this->get('/api/v1/events', ['cursor' => PositionCursor::encode(5)]);
        self::assertSame([6], array_column($this->dataRows(), 'position'));
    }

    public function testUnpositionedEventsAreNotListed(): void
    {
        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));

        $this->get('/api/v1/events');

        self::assertSame([], $this->at('data'));
        self::assertNull($this->at('next_cursor'));
    }

    public function testFiltersByTypeAndWildcard(): void
    {
        $invoice = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::issued($invoice));
        $this->events->record($this->tenant, RecordedEvents::payment(Id::generate(), $invoice));
        $this->events->record($this->tenant, RecordedEvents::paid($invoice));
        $this->events->record($this->tenant, RecordedEvents::trashed($invoice));
        $this->events->number();

        $this->get('/api/v1/events', ['types' => 'invoicing.invoice.paid']);
        self::assertSame(['invoicing.invoice.paid'], array_column($this->dataRows(), 'type'));

        $this->get('/api/v1/events', ['types' => 'invoicing.payment.recorded, invoicing.invoice.trashed']);
        self::assertSame(['invoicing.payment.recorded', 'invoicing.invoice.trashed'], array_column($this->dataRows(), 'type'));

        $this->get('/api/v1/events', ['types' => 'invoicing.*']);
        self::assertCount(4, $this->dataRows());
    }

    public function testUnknownTypeIs400(): void
    {
        foreach (['invoicing.invoice.nothing', 'nothing.*', 'invoicing.invoice.paid,wrong'] as $types) {
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
        $this->inTenant($this->tenant, function (): void {
            $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
            $this->em()->persist($party);
            $membership = $this->em()->getRepository(Membership::class)->findOneBy([]) ?? throw new \LogicException('No membership.');
            $membership->changeRole(Role::Guest, $party);
        });

        $this->get('/api/v1/events');

        $this->assertProblem(401, 'invalid_token');
    }

    public function testOtherTenantsEventsAreInvisible(): void
    {
        $theirs = $this->foreignTenant();
        $this->events->record($theirs, RecordedEvents::issued(Id::generate()));
        $mine = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::issued($mine));
        $this->events->number();

        $this->get('/api/v1/events');

        self::assertSame([$mine], array_column(array_column($this->dataRows(), 'subject'), 'id'));
    }

    public function testOlderThanThirtyDaysIsNotListed(): void
    {
        $old = Id::generate();
        $fresh = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::issued($old));
        $this->events->record($this->tenant, RecordedEvents::issued($fresh));
        $this->events->number();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE event_log SET occurred_at = :at WHERE subject_id = :subject', ['at' => new \DateTimeImmutable('-31 days')->format('Y-m-d H:i:s'), 'subject' => $old]);

        $this->get('/api/v1/events');

        self::assertSame([$fresh], array_column(array_column($this->dataRows(), 'subject'), 'id'));
    }

    public function testEnvelopeShape(): void
    {
        $invoice = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::issued($invoice));
        $payment = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::payment($payment, $invoice));
        $this->events->number();

        $this->get('/api/v1/events');

        self::assertSame(['id', 'type', 'version', 'position', 'occurred_at', 'subject', 'data', 'correlation_id', 'actor'], array_keys($this->dataRows()[0]));
        self::assertTrue(Id::isValid($this->text('data', 0, 'id')));
        self::assertSame('invoicing.invoice.issued', $this->at('data', 0, 'type'));
        self::assertSame(1, $this->at('data', 0, 'version'));
        self::assertSame(1, $this->at('data', 0, 'position'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $this->text('data', 0, 'occurred_at'));
        self::assertSame(['type' => 'invoice', 'id' => $invoice, 'url' => '/api/v1/invoices/'.$invoice], $this->at('data', 0, 'subject'));
        self::assertSame(['kind' => 'system'], $this->at('data', 0, 'actor'));
        self::assertNull($this->at('data', 0, 'correlation_id'));
        self::assertSame([
            'invoice_id' => $invoice,
            'kind' => 'invoice',
            'number' => '2026-0001',
            'series_id' => $this->at('data', 0, 'data', 'series_id'),
            'firm_id' => $this->at('data', 0, 'data', 'firm_id'),
            'customer_id' => $this->at('data', 0, 'data', 'customer_id'),
            'currency' => 'CZK',
            'total' => '1210.00',
            'issued_on' => '2026-11-12',
            'due_on' => '2026-11-26',
            'corrects' => null,
        ], $this->at('data', 0, 'data'));
        self::assertSame(['type' => 'payment', 'id' => $payment, 'url' => '/api/v1/payments/'.$payment], $this->at('data', 1, 'subject'));
        self::assertSame([$invoice], $this->at('data', 1, 'data', 'invoice_ids'));

        $body = (string) $this->client->getResponse()->getContent();
        foreach (['tenant', 'user_id', 'api_token', $this->tenant->toString(), $this->user->id()] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
    }

    public function testWriteScopeNotNeeded(): void
    {
        $this->get('/api/v1/events', secret: $this->issued([ApiScope::Write]));
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/events', secret: $this->issued([ApiScope::Read]));
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
