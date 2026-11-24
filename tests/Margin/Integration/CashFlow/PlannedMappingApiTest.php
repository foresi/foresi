<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\CashFlow;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\SupplierDefault;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * The mapping of a planned item and the default recipient of a supplier over
 * REST and MCP, on the planned items Cash flow gives Margin and the received
 * documents Purchasing gives it. Who may work with them at all is in
 * tests/Margin/Infrastructure. Today is 10 December 2026.
 */
final class PlannedMappingApiTest extends ApiTestCase
{
    private const array COST = [
        'direction' => 'out',
        'name' => 'Cloud Host',
        'category' => 'software',
        'amount' => '12100.00',
        'on' => '2026-12-05',
        'repeat' => 'month',
    ];

    private string $writer;
    private string $clientA;
    private string $supplier;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('margin:write'), ApiScope::from('margin:read'), ApiScope::from('cashflow:write'), ApiScope::from('received_documents:write')]);
        $this->clientA = $this->customerId;
        $this->supplier = $this->flushed(function (): string {
            $party = new Party(PartyKind::Person, 'Cloud Host', null, null, '+420 000 000 001');
            $this->em()->persist($party);

            return $party->id();
        });
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAMappingIsSetReadAndEndedByMonth(): void
    {
        $item = $this->planned();
        $split = ['kind' => 'percent', 'shares' => [['recipient' => $this->clientA, 'percent' => '40'], ['recipient' => 'own', 'percent' => '60']]];

        $this->send('PUT', $this->allocationPath($item), ['allocation' => $split, 'from_month' => '2026-12']);

        self::assertResponseIsSuccessful();
        self::assertSame([$item, 'Cloud Host', 1], [$this->at('data', 'planned_item_id'), $this->at('data', 'name'), \count((array) $this->at('data', 'versions'))]);
        self::assertSame(['2026-12', true, 'percent'], [$this->at('data', 'versions', 0, 'from_month'), $this->at('data', 'versions', 0, 'current'), $this->at('data', 'versions', 0, 'allocation', 'kind')]);
        self::assertSame([$this->clientA, 'Example Customer a.s.', '40', null, false], [
            $this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'recipient'),
            $this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'recipient_name'),
            $this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'percent'),
            $this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'amount'),
            $this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'rest'),
        ]);
        self::assertIsString($this->at('data', 'versions', 0, 'created_by'));

        // Another month adds a version, and the same month replaces its version.
        $this->send('PUT', $this->allocationPath($item), ['allocation' => null, 'from_month' => '2027-02']);
        $this->send('PUT', $this->allocationPath($item), ['allocation' => ['kind' => 'whole', 'shares' => [['recipient' => 'own']]], 'from_month' => '2026-12']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->em()->getRepository(PlannedAllocation::class)->count([]));

        $this->get($this->allocationPath($item), secret: $this->writer);
        self::assertSame(['2027-02', null, false, '2026-12', 'whole', true], [
            $this->at('data', 'versions', 0, 'from_month'), $this->at('data', 'versions', 0, 'allocation'), $this->at('data', 'versions', 0, 'current'),
            $this->at('data', 'versions', 1, 'from_month'), $this->at('data', 'versions', 1, 'allocation', 'kind'), $this->at('data', 'versions', 1, 'current'),
        ], 'Latest first; the version in force this month is the one from December, as the one from February is not yet.');
    }

    public function testAFixedMappingKeepsItsAmountAndItsRest(): void
    {
        $item = $this->planned();

        $this->send('PUT', $this->allocationPath($item), ['allocation' => ['kind' => 'fixed', 'shares' => [['recipient' => $this->clientA, 'amount' => '800.00'], ['recipient' => 'own', 'rest' => true]]], 'from_month' => '2026-12']);

        self::assertResponseIsSuccessful();
        self::assertSame([80_000, 'CZK', true], [$this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'amount', 'amount_minor'), $this->at('data', 'versions', 0, 'allocation', 'shares', 0, 'amount', 'currency'), $this->at('data', 'versions', 0, 'allocation', 'shares', 1, 'rest')]);
    }

    public function testWhatCannotBeMappedIsRefusedWithItsCode(): void
    {
        $item = $this->planned();
        $whole = ['kind' => 'whole', 'shares' => [['recipient' => 'own']]];
        $path = $this->allocationPath($item);

        $this->send('PUT', $path, ['allocation' => $whole, 'from_month' => '2026-13']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'from_month', 'reason' => 'invalid_month']);
        $this->send('PUT', $path, ['allocation' => $whole, 'from_month' => 'dec-26']);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'invalid_month']);
        $this->send('PUT', $path, ['allocation' => ['shares' => []], 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'kind_invalid']);
        $this->send('PUT', $path, ['allocation' => ['kind' => 'whole', 'shares' => 'own'], 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'share_invalid']);
        $this->send('PUT', $path, ['allocation' => ['kind' => 'whole', 'shares' => [['recipient' => Uuid::v7()->toRfc4122()]]], 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'unknown_contact']);
        $this->send('PUT', $path, ['allocation' => ['kind' => 'percent', 'shares' => [['recipient' => 'own', 'percent' => '60'], ['recipient' => $this->clientA, 'percent' => '30']]], 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'percent_must_add_up']);
        $this->send('PUT', $path, ['allocation' => 'whole', 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'allocation']);
        $this->send('PUT', $path, ['from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'allocation']);

        $this->send('PUT', $this->allocationPath(Uuid::v7()->toRfc4122()), ['allocation' => $whole, 'from_month' => '2026-12']);
        $this->assertProblem(404, 'unknown_planned_item');
        $this->get($this->allocationPath(Uuid::v7()->toRfc4122()), secret: $this->writer);
        $this->assertProblem(404, 'unknown_planned_item');

        $income = $this->planned(['direction' => 'in', 'category' => 'revenue']);
        $this->send('PUT', $this->allocationPath($income), ['allocation' => $whole, 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'planned_item_id', 'reason' => 'not_an_expense']);
        $oneOff = $this->planned(['repeat' => null]);
        $this->send('PUT', $this->allocationPath($oneOff), ['allocation' => $whole, 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'not_recurring']);
        $nobody = $this->planned(['counterparty_id' => null]);
        $this->send('PUT', $this->allocationPath($nobody), ['allocation' => $whole, 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'counterparty_required']);
        // Ending a mapping needs no counterparty.
        $this->send('PUT', $this->allocationPath($nobody), ['allocation' => null, 'from_month' => '2026-12']);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->em()->getRepository(PlannedAllocation::class)->count([]));
    }

    public function testASupplierDefaultIsSetReadAndEndedByMonth(): void
    {
        $path = $this->defaultPath($this->supplier);

        $this->send('PUT', $path, ['recipient' => 'own', 'from_month' => '2026-12']);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->supplier, 'Cloud Host'], [$this->at('data', 'supplier_id'), $this->at('data', 'supplier_name')]);
        self::assertSame(['2026-12', 'own', null, true], [$this->at('data', 'versions', 0, 'from_month'), $this->at('data', 'versions', 0, 'recipient'), $this->at('data', 'versions', 0, 'recipient_name'), $this->at('data', 'versions', 0, 'current')]);

        $this->send('PUT', $path, ['recipient' => $this->clientA, 'from_month' => '2027-03']);
        $this->send('PUT', $path, ['recipient' => null, 'from_month' => '2027-06']);
        $this->send('PUT', $path, ['recipient' => $this->clientA, 'from_month' => '2026-12']);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->em()->getRepository(SupplierDefault::class)->count([]));

        $this->get($path, secret: $this->writer);
        self::assertSame([['2027-06', null, null, false], ['2027-03', $this->clientA, 'Example Customer a.s.', false], ['2026-12', $this->clientA, 'Example Customer a.s.', true]], array_map(
            fn (int $index): array => [$this->at('data', 'versions', $index, 'from_month'), $this->at('data', 'versions', $index, 'recipient'), $this->at('data', 'versions', $index, 'recipient_name'), $this->at('data', 'versions', $index, 'current')],
            [0, 1, 2],
        ));
    }

    public function testWhatIsNotADefaultIsRefusedWithItsCode(): void
    {
        $path = $this->defaultPath($this->supplier);

        $this->send('PUT', $path, ['recipient' => 'own', 'from_month' => '2026-1']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'from_month', 'reason' => 'invalid_month']);
        $this->send('PUT', $path, ['recipient' => Uuid::v7()->toRfc4122(), 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'recipient', 'reason' => 'unknown_contact']);
        $this->send('PUT', $path, ['recipient' => 'somebody', 'from_month' => '2026-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'recipient', 'reason' => 'recipient_invalid']);
        $this->send('PUT', $this->defaultPath(Uuid::v7()->toRfc4122()), ['recipient' => 'own', 'from_month' => '2026-12']);
        $this->assertProblem(404, 'unknown_supplier');
        $this->send('PUT', $this->defaultPath('not-an-id'), ['recipient' => 'own', 'from_month' => '2026-12']);
        $this->assertProblem(404, 'unknown_supplier');
        $this->get($this->defaultPath(Uuid::v7()->toRfc4122()), secret: $this->writer);
        $this->assertProblem(404, 'unknown_supplier');

        self::assertSame(0, $this->em()->getRepository(SupplierDefault::class)->count([]));
    }

    public function testTheDocumentShowsWhereAPlannedItemAndSupplierShareCameFrom(): void
    {
        $item = $this->planned();
        $this->send('POST', '/api/v1/received-documents', [
            'kind' => 'tax_invoice',
            'supplier_id' => $this->supplier,
            'supplier_number' => 'CH-1',
            'issue_date' => '2026-12-02',
            'due_date' => '2026-12-16',
            'received_date' => '2026-12-03',
            'total' => '1210.00',
            'vat_recap' => ['21:1000.00:210.00'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $this->flushed(function () use ($id, $item): void {
            foreach ($this->em()->getRepository(CostShare::class)->findBy(['documentId' => $id]) as $stored) {
                $this->em()->remove($stored);
            }
            $this->em()->flush();
            $this->em()->persist(new CostShare('00000000-0000-7000-8000-000000000001', $id, 'rate:21', new \DateTimeImmutable('2026-12-01'), $this->clientA, 60_000, CostShareSource::PlannedItem, $item, new \DateTimeImmutable('2026-12-05')));
            $this->em()->persist(new CostShare('00000000-0000-7000-8000-000000000002', $id, 'rate:21', new \DateTimeImmutable('2026-12-01'), 'own', 40_000, CostShareSource::Supplier));
        });

        $this->get('/api/v1/margin/documents/'.$id, secret: $this->writer);

        self::assertResponseIsSuccessful();
        self::assertSame(['planned_item', $item, '2026-12-05'], [$this->at('data', 'lines', 0, 'shares', 0, 'source'), $this->at('data', 'lines', 0, 'shares', 0, 'planned_item_id'), $this->at('data', 'lines', 0, 'shares', 0, 'occurrence_on')]);
        self::assertSame(['supplier', null, null], [$this->at('data', 'lines', 0, 'shares', 1, 'source'), $this->at('data', 'lines', 0, 'shares', 1, 'planned_item_id'), $this->at('data', 'lines', 0, 'shares', 1, 'occurrence_on')]);
    }

    public function testTheToolsDoWhatTheEndpointsDoAndARepeatedKeyActsOnce(): void
    {
        $item = $this->planned();
        $arguments = ['planned_item_id' => $item, 'allocation' => ['kind' => 'whole', 'shares' => [['recipient' => $this->clientA]]], 'from_month' => '2026-12', 'idempotency_key' => 'plan-1'];

        $set = $this->tool('foresi_set_planned_item_allocation', $arguments);
        self::assertFalse($set['isError'] ?? false);
        self::assertSame([$this->clientA, '2026-12'], [$this->in($set, 'structuredContent', 'data', 'versions', 0, 'allocation', 'shares', 0, 'recipient'), $this->in($set, 'structuredContent', 'data', 'versions', 0, 'from_month')]);
        $repeated = $this->tool('foresi_set_planned_item_allocation', $arguments);
        self::assertTrue($this->in($repeated, 'structuredContent', 'idempotent_replayed'));
        self::assertSame(1, $this->em()->getRepository(PlannedAllocation::class)->count([]));
        $read = $this->tool('foresi_get_planned_item_allocation', ['planned_item_id' => $item]);
        self::assertSame($this->in($set, 'structuredContent', 'data'), $this->in($read, 'structuredContent', 'data'));

        $default = $this->tool('foresi_set_supplier_default_recipient', ['contact_id' => $this->supplier, 'recipient' => 'own', 'from_month' => '2026-12', 'idempotency_key' => 'default-1']);
        self::assertFalse($default['isError'] ?? false);
        $readDefault = $this->tool('foresi_get_supplier_default_recipient', ['contact_id' => $this->supplier]);
        self::assertSame($this->in($default, 'structuredContent', 'data'), $this->in($readDefault, 'structuredContent', 'data'));
        self::assertSame('own', $this->in($readDefault, 'structuredContent', 'data', 'versions', 0, 'recipient'));
    }

    public function testTheToolsRefuseWithTheCodeOfTheRefusal(): void
    {
        $call = $this->tool('foresi_set_planned_item_allocation', ['planned_item_id' => Uuid::v7()->toRfc4122(), 'allocation' => null, 'from_month' => '2026-12', 'idempotency_key' => 'bad-1']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('unknown_planned_item', $this->in($call, 'structuredContent', 'error', 'code'));

        $call = $this->tool('foresi_set_supplier_default_recipient', ['contact_id' => $this->supplier, 'recipient' => 'own', 'from_month' => 'soon', 'idempotency_key' => 'bad-2']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame(['parameter' => 'from_month', 'reason' => 'invalid_month'], $this->in($call, 'structuredContent', 'error', 'details'));

        $call = $this->tool('foresi_get_supplier_default_recipient', ['contact_id' => Uuid::v7()->toRfc4122()]);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('unknown_supplier', $this->in($call, 'structuredContent', 'error', 'code'));
    }

    public function testTheSchemaOfTheMappingToolTakesOneObject(): void
    {
        $this->tool('foresi_get_planned_item_allocation', ['planned_item_id' => $this->planned()]);
        $tools = array_column((array) $this->in($this->mcp($this->writer, 'tools/list'), 'result', 'tools'), null, 'name');

        self::assertSame(['object', 'null'], $this->in($tools['foresi_set_planned_item_allocation'], 'inputSchema', 'properties', 'allocation', 'type'));
        $required = $this->in($tools['foresi_set_planned_item_allocation'], 'inputSchema', 'required');
        self::assertIsArray($required);
        self::assertContains('allocation', $required);
    }

    /**
     * @param array<string, mixed> $changes a null leaves the field out
     */
    private function planned(array $changes = []): string
    {
        $this->send('POST', '/api/v1/cash-flow/planned-items', array_filter($changes + self::COST + ['counterparty_id' => $this->supplier], static fn (mixed $value): bool => null !== $value));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    private function allocationPath(string $plannedItemId): string
    {
        return '/api/v1/margin/planned-items/'.$plannedItemId.'/allocation';
    }

    private function defaultPath(string $contactId): string
    {
        return '/api/v1/margin/suppliers/'.$contactId.'/default-recipient';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp($this->writer, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $result = $this->in($this->mcp($this->writer, 'tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $secret, string $method, array $params = []): array
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
