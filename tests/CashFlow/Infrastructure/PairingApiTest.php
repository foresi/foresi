<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\Pairing;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Application\PlannedOccurrences;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\FakePairableDocuments;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Pairing a received document with a planned occurrence, unpairing it and
 * listing the pairings over REST and MCP, declared once with #[Operation].
 * A fake document source stands in for the module that holds the documents.
 */
final class PairingApiTest extends ApiTestCase
{
    private FakePairableDocuments $documents;
    private string $writer;
    private string $reader;
    private string $supplierId;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-17 10:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('cashflow:write'), ApiScope::from('cashflow:read')]);
        $this->reader = $this->issued([ApiScope::from('cashflow:read')]);
        $this->supplierId = $this->flushed(function (): string {
            $supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11111111'), null, '');
            $this->em()->persist($supplier);

            return $supplier->id();
        });
        $this->documents = new FakePairableDocuments();
        $c = self::getContainer();
        $c->set(Pairing::class, new Pairing(
            $c->get(CashFlowStore::class), $c->get(PlannedItemVatRule::class), $c->get(PlannedOccurrences::class), $c->get(InstanceClock::class),
            $c->get(TransactionalWork::class), $c->get(EventRecorder::class), $c->get(ClockInterface::class), [$this->documents],
        ));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testPairsADocumentWithAnOccurrenceAndAnswersTheRow(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-18');

        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-20']);

        self::assertResponseStatusCodeSame(200);
        $row = $this->row();
        self::assertSame(['type' => 'received_document', 'id' => 'doc-1', 'url' => '/api/v1/received-documents/doc-1'], $row['subject']);
        self::assertSame(['confirmed', $this->supplierId, $item, 'Cloud Host', '2026-11-20', [['planned_item_id' => $item, 'planned_item_name' => 'Cloud Host', 'occurrence_on' => '2026-11-20']], $this->user->id()], [
            $row['state'], $row['counterparty_id'], $row['planned_item_id'], $row['planned_item_name'], $row['occurrence_on'], $row['candidates'], $row['decided_by'],
        ]);
        self::assertIsString($row['decided_at']);
        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame(['confirmed', $item, '2026-11-20', null], [$events[0]['state'], $events[0]['planned_item_id'], $events[0]['occurrence_on'], $events[0]['previous_planned_item_id']]);

        $this->get('/api/v1/cash-flow/pairings', ['state' => 'confirmed'], $this->reader);
        self::assertSame([$row], $this->rows());
    }

    public function testPairingAgainWithTheSameOccurrenceChangesNothing(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-18');
        $body = ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-20'];
        $this->send('POST', '/api/v1/cash-flow/pairings', $body);
        $this->send('POST', '/api/v1/cash-flow/pairings', $body);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->events());
    }

    public function testAPersonPairsAProposalAndTheOtherDocumentsOfThePeriodStayProposed(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-05');
        $this->document('doc-2', '100.00', '2026-11-18');
        $this->considerAll();
        $this->get('/api/v1/cash-flow/pairings', ['state' => 'proposed'], $this->reader);
        self::assertCount(2, $this->rows());

        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_type' => 'received_document', 'subject_id' => 'doc-2', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-20']);

        $this->get('/api/v1/cash-flow/pairings', [], $this->reader);
        self::assertSame(['proposed', 'confirmed'], array_column($this->rows(), 'state'));
    }

    public function testRefusesWhatCannotBePaired(): void
    {
        $item = $this->monthly('Cloud Host');
        $income = $this->monthly('Retainer', 'in');
        $this->document('doc-1', '100.00', '2026-11-18');
        $ok = ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-20'];

        $this->send('POST', '/api/v1/cash-flow/pairings', ['planned_item_id' => $income] + $ok);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'planned_item_id', 'reason' => 'direction_mismatch']);

        $this->send('POST', '/api/v1/cash-flow/pairings', ['occurrence_on' => '2026-11-21'] + $ok);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'occurrence_on', 'reason' => 'not_an_occurrence']);

        $this->send('POST', '/api/v1/cash-flow/pairings', ['occurrence_on' => '2026-11-2x'] + $ok);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'occurrence_on', 'reason' => 'date_invalid']);

        $this->send('POST', '/api/v1/cash-flow/pairings', ['planned_item_id' => '0198a4f0-8b1e-7000-8000-000000000001'] + $ok);
        $this->assertProblem(404, 'not_found');

        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_id' => 'unknown'] + $ok);
        $this->assertProblem(404, 'not_found');
        self::assertSame([], $this->events());
    }

    public function testASkippedOccurrenceAndOneAfterTheEndAreNotOccurrences(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-18');
        $this->send('POST', '/api/v1/cash-flow/planned-items/'.$item.'/skip', ['on' => '2026-12-20']);
        $this->send('POST', '/api/v1/cash-flow/planned-items/'.$item.'/end', ['on' => '2027-01-31']);
        $ok = ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item];

        foreach (['2026-12-20', '2027-02-20'] as $day) {
            $this->send('POST', '/api/v1/cash-flow/pairings', ['occurrence_on' => $day] + $ok);
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'occurrence_on', 'reason' => 'not_an_occurrence']);
        }
        $this->send('POST', '/api/v1/cash-flow/pairings', ['occurrence_on' => '2027-01-20'] + $ok);
        self::assertResponseIsSuccessful();
    }

    public function testUnpairingDeclinesTheDocumentAndLooksAtItsNeighboursAgain(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-05');
        $this->document('doc-2', '100.00', '2026-11-18');
        $this->considerAll();
        self::assertSame(['proposed', 'proposed'], $this->states());

        $this->send('DELETE', '/api/v1/cash-flow/pairings/received_document/doc-1');

        self::assertResponseStatusCodeSame(200);
        $row = $this->row();
        self::assertSame(['declined', null, null, [['planned_item_id' => $item, 'planned_item_name' => 'Cloud Host', 'occurrence_on' => '2026-11-20']], $this->user->id()], [
            $row['state'], $row['planned_item_id'], $row['occurrence_on'], $row['candidates'], $row['decided_by'],
        ]);
        self::assertSame(['declined', 'auto'], $this->states(), 'The document left alone in the period pairs on its own again.');

        $this->send('DELETE', '/api/v1/cash-flow/pairings/received_document/doc-1');
        self::assertResponseStatusCodeSame(200);
        $this->send('DELETE', '/api/v1/cash-flow/pairings/received_document/nobody');
        $this->assertProblem(404, 'not_found');
    }

    public function testAConfirmedPairingCanBeUnpaired(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-18');
        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-20']);

        $this->send('DELETE', '/api/v1/cash-flow/pairings/received_document/doc-1');

        self::assertSame(['declined', null], [$this->at('data', 'state'), $this->at('data', 'planned_item_id')]);
        $events = $this->events();
        self::assertSame(['declined', null, null, $item], [$events[1]['state'], $events[1]['planned_item_id'], $events[1]['occurrence_on'], $events[1]['previous_planned_item_id']]);
    }

    public function testListsThePairingsByStateAndItemAndRefusesABadFilter(): void
    {
        $a = $this->monthly('Cloud Host');
        $b = $this->monthly('Voice API');
        $this->document('doc-1', '100.00', '2026-11-18');
        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $b, 'occurrence_on' => '2026-11-20']);

        $this->get('/api/v1/cash-flow/pairings', ['planned_item_id' => $b], $this->reader);
        self::assertSame(['doc-1'], array_column(array_column($this->rows(), 'subject'), 'id'));
        $this->get('/api/v1/cash-flow/pairings', ['planned_item_id' => $a], $this->reader);
        self::assertSame([], $this->rows());
        $this->document('doc-2', '100.00', '2026-12-18');
        $this->considerAll();
        $this->get('/api/v1/cash-flow/pairings', ['planned_item_id' => $a], $this->reader);
        self::assertSame(['doc-2'], array_column(array_column($this->rows(), 'subject'), 'id'), 'A proposal lists the item among its candidates.');
        $this->get('/api/v1/cash-flow/pairings', ['planned_item_id' => $b, 'state' => 'proposed'], $this->reader);
        self::assertSame(['doc-2'], array_column(array_column($this->rows(), 'subject'), 'id'));
        $this->get('/api/v1/cash-flow/pairings', ['state' => 'declined'], $this->reader);
        self::assertSame([], $this->rows());
        $this->get('/api/v1/cash-flow/pairings', ['state' => 'maybe'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'state', 'reason' => 'state_invalid']);
        $this->get('/api/v1/cash-flow/pairings', ['planned_item_id' => 'x'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'planned_item_id', 'reason' => 'planned_item_invalid']);
    }

    public function testPairsUnpairsAndListsOverMcp(): void
    {
        $item = $this->monthly('Cloud Host');
        $this->document('doc-1', '100.00', '2026-11-18');

        $call = $this->tool('foresi_pair_document', ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-20', 'idempotency_key' => 'pair-1']);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame(['confirmed', $item, '2026-11-20'], [$this->in($call, 'structuredContent', 'data', 'state'), $this->in($call, 'structuredContent', 'data', 'planned_item_id'), $this->in($call, 'structuredContent', 'data', 'occurrence_on')]);

        $call = $this->tool('foresi_list_pairings', ['state' => 'confirmed']);
        self::assertSame(['doc-1'], array_column(array_column((array) $this->in($call, 'structuredContent', 'data', 'items'), 'subject'), 'id'));
        $text = $this->in($call, 'content', 0, 'text');
        self::assertIsString($text);
        self::assertStringContainsString('1 pairing.', $text);

        $call = $this->tool('foresi_pair_document', ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'planned_item_id' => $item, 'occurrence_on' => '2026-11-21', 'idempotency_key' => 'pair-2']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame(['parameter' => 'occurrence_on', 'reason' => 'not_an_occurrence'], $this->in($call, 'structuredContent', 'error', 'details'));

        // The input schema lists the four states, so the server refuses another before the tool runs.
        $answer = $this->mcp('tools/call', ['name' => 'foresi_list_pairings', 'arguments' => ['state' => 'maybe']]);
        $result = $answer['result'] ?? null;
        self::assertTrue(isset($answer['error']) || (\is_array($result) && true === ($result['isError'] ?? false)));

        $call = $this->tool('foresi_unpair_document', ['subject_type' => 'received_document', 'subject_id' => 'doc-1', 'idempotency_key' => 'unpair-1']);
        self::assertSame('declined', $this->in($call, 'structuredContent', 'data', 'state'));

        $call = $this->tool('foresi_unpair_document', ['subject_type' => 'received_document', 'subject_id' => 'nobody', 'idempotency_key' => 'unpair-2']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('not_found', $this->in($call, 'structuredContent', 'error', 'code'));
    }

    public function testATokenWithoutTheWriteScopeCannotPairAndOneWithoutTheReadScopeCannotList(): void
    {
        $this->client->request('POST', '/api/v1/cash-flow/pairings', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->reader, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/cash-flow/pairings', [], $this->issued([ApiScope::from('contacts:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return self::asRow($this->at('data'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function asRow(mixed $value): array
    {
        self::assertIsArray($value);

        return array_combine(array_map(strval(...), array_keys($value)), $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $data = $this->at('data');
        self::assertIsArray($data);

        return array_map(self::asRow(...), array_values($data));
    }

    private function monthly(string $name, string $direction = 'out'): string
    {
        $this->send('POST', '/api/v1/cash-flow/planned-items', ['direction' => $direction, 'name' => $name, 'category' => 'in' === $direction ? 'revenue' : 'software', 'amount' => '100.00', 'on' => '2026-11-20', 'repeat' => 'month', 'counterparty_id' => $this->supplierId]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    private function document(string $id, string $amount, string $on): void
    {
        $this->documents->add(new PairableDocument(new FlowSubject('received_document', $id, '/api/v1/received-documents/'.$id), $this->supplierId, FlowDirection::Out, Money::of($amount, Currency::CZK), new \DateTimeImmutable($on)));
    }

    private function considerAll(): void
    {
        $pairing = self::getContainer()->get(Pairing::class);
        foreach ($this->documents->since(new \DateTimeImmutable('2000-01-01')) as $document) {
            $pairing->consider($document);
        }
    }

    /**
     * @return list<string>
     */
    private function states(): array
    {
        $this->get('/api/v1/cash-flow/pairings', [], $this->reader);

        return array_map(static fn (array $row): string => \is_string($row['state']) ? $row['state'] : '', $this->rows());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, ['cash-flow.document_pairing.changed']);

        return array_map(static fn (mixed $row): array => self::asRow(json_decode(\is_string($row) ? $row : '', true, flags: \JSON_THROW_ON_ERROR)), $rows);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $result = $this->in($this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $method, array $params = []): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
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
