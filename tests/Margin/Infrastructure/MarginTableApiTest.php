<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Infrastructure;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The margin table and its cells over REST and MCP, on stored cost shares so
 * that no module that has costs or revenue is needed (the revenue side is
 * tested with Invoicing's source). Who may read at all is in
 * MarginAccessApiTest. Today is 10 December 2026.
 */
final class MarginTableApiTest extends ApiTestCase
{
    private string $reader;
    private string $clientA;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->reader = $this->issued([ApiScope::from('margin:read')]);
        $this->clientA = $this->customerId;
        $this->flushed(function (): void {
            $this->em()->persist(new CostShare('00000000-0000-7000-8000-000000000001', '00000000-0000-7000-8000-0000000000d1', 'rate:21', new \DateTimeImmutable('2026-11-01'), $this->clientA, 50_820, CostShareSource::Line));
            $this->em()->persist(new CostShare('00000000-0000-7000-8000-000000000002', '00000000-0000-7000-8000-0000000000d2', 'rate:21', new \DateTimeImmutable('2026-11-01'), 'own', 51_744, CostShareSource::Supplier));
            $this->em()->persist(new CostShare('00000000-0000-7000-8000-000000000003', '00000000-0000-7000-8000-0000000000d3', 'rate:21', new \DateTimeImmutable('2026-12-01'), null, 10_000, CostShareSource::Unassigned));
        });
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheTableHasARowForEveryRecipientAndACellForEveryMonth(): void
    {
        $this->get('/api/v1/margin', ['from' => '2026-11', 'to' => '2026-12'], $this->reader);

        self::assertResponseIsSuccessful();
        self::assertSame(['2026-11', '2026-12', ['2026-11', '2026-12']], [$this->at('data', 'from'), $this->at('data', 'to'), $this->at('data', 'months')]);
        $rows = $this->at('data', 'rows');
        self::assertIsArray($rows);
        self::assertSame(['client:'.$this->clientA, 'own', 'unassigned'], array_column($rows, 'row'));
        self::assertSame(['client', 'own', 'unassigned'], array_column($rows, 'kind'));
        self::assertSame(['Example Customer a.s.', 'Own use', 'Unassigned'], array_column($rows, 'label'));
        self::assertSame([$this->clientA, null, null], array_column($rows, 'client_id'));
        self::assertSame([['month' => '2026-11', 'revenue' => 0, 'costs' => 50_820, 'margin' => -50_820], ['month' => '2026-12', 'revenue' => 0, 'costs' => 0, 'margin' => 0]], $this->cells(0));
        self::assertSame([['2026-11', 0, 0], ['2026-12', 10_000, -10_000]], array_map(static fn (array $cell): array => [$cell['month'], $cell['costs'], $cell['margin']], $this->cells(2)));
        self::assertSame(['CZK', 112_564], [$this->at('data', 'total', 'costs', 'currency'), $this->at('data', 'total', 'costs', 'amount_minor')]);
        self::assertSame(-112_564, $this->at('data', 'total', 'margin', 'amount_minor'));
        self::assertSame([['2026-11', 102_564], ['2026-12', 10_000]], array_map(fn (mixed $cell): array => [$this->in($cell, 'month'), $this->in($cell, 'costs', 'amount_minor')], (array) $this->at('data', 'month_totals')));
        self::assertSame([], $this->at('data', 'known_gaps'));
    }

    public function testTheRangeDefaultsToTheLastThreeMonths(): void
    {
        $this->get('/api/v1/margin', secret: $this->reader);

        self::assertResponseIsSuccessful();
        self::assertSame(['2026-10', '2026-11', '2026-12'], $this->at('data', 'months'));
        self::assertSame('unassigned', $this->at('data', 'rows', 2, 'row'));
    }

    public function testTheUnassignedRowShowsEvenWhenNothingIsUnassigned(): void
    {
        $this->get('/api/v1/margin', ['from' => '2026-01', 'to' => '2026-01'], $this->reader);

        self::assertResponseIsSuccessful();
        self::assertSame(['own', 'unassigned'], array_column((array) $this->at('data', 'rows'), 'row'));
    }

    public function testARangeThatCannotBeUsedIsRefusedWithItsCode(): void
    {
        $this->get('/api/v1/margin', ['from' => '2026-13', 'to' => '2026-12'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'from', 'reason' => 'invalid_month']);
        $this->get('/api/v1/margin', ['from' => '2026-12', 'to' => '2026-11'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'range_reversed']);
        $this->get('/api/v1/margin', ['from' => '2024-11', 'to' => '2026-11'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'to', 'reason' => 'range_too_long']);
        $this->get('/api/v1/margin', ['from' => '0001-01', 'to' => '9999-12'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'range_too_long']);
    }

    public function testACellListsTheCostSharesBehindIt(): void
    {
        $this->get('/api/v1/margin/cell', ['row' => 'client:'.$this->clientA, 'month' => '2026-11'], $this->reader);

        self::assertResponseIsSuccessful();
        self::assertSame(['client:'.$this->clientA, 'Example Customer a.s.', '2026-11'], [$this->at('data', 'row'), $this->at('data', 'label'), $this->at('data', 'month')]);
        self::assertSame([0, 50_820, -50_820], [$this->at('data', 'revenue', 'amount_minor'), $this->at('data', 'costs', 'amount_minor'), $this->at('data', 'margin', 'amount_minor')]);
        self::assertSame([], $this->at('data', 'revenue_items'));
        self::assertCount(1, (array) $this->at('data', 'cost_items'));
        self::assertSame(['00000000-0000-7000-8000-0000000000d1', 50_820, 'line'], [$this->at('data', 'cost_items', 0, 'document_id'), $this->at('data', 'cost_items', 0, 'amount', 'amount_minor'), $this->at('data', 'cost_items', 0, 'source')]);

        $this->get('/api/v1/margin/cell', ['row' => 'unassigned', 'month' => '2026-12'], $this->reader);
        self::assertSame(['Unassigned', 10_000, 'unassigned'], [$this->at('data', 'label'), $this->at('data', 'costs', 'amount_minor'), $this->at('data', 'cost_items', 0, 'source')]);
    }

    public function testACellThatCannotBeNamedIsRefusedWithItsCode(): void
    {
        $this->get('/api/v1/margin/cell', ['row' => 'client:not-an-id', 'month' => '2026-11'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'row', 'reason' => 'invalid_row']);
        $this->get('/api/v1/margin/cell', ['month' => '2026-11'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'invalid_row']);
        $this->get('/api/v1/margin/cell', ['row' => 'own', 'month' => 'November'], $this->reader);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'month', 'reason' => 'invalid_month']);
    }

    public function testATokenWithoutTheScopeIsRefused(): void
    {
        $other = $this->issued([ApiScope::from('firm:read')]);

        $this->get('/api/v1/margin', secret: $other);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/margin/cell', ['row' => 'own', 'month' => '2026-11'], $other);
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testTheToolsAnswerWhatTheEndpointsAnswer(): void
    {
        $table = $this->tool('foresi_client_margin', ['from' => '2026-11', 'to' => '2026-12']);
        self::assertFalse($table['isError'] ?? false);
        $this->get('/api/v1/margin', ['from' => '2026-11', 'to' => '2026-12'], $this->reader);
        self::assertSame($this->at('data'), $this->in($table, 'structuredContent', 'data'));

        $cell = $this->tool('foresi_client_margin_cell', ['row' => 'own', 'month' => '2026-11']);
        self::assertFalse($cell['isError'] ?? false);
        self::assertSame(['Own use', 51_744], [$this->in($cell, 'structuredContent', 'data', 'label'), $this->in($cell, 'structuredContent', 'data', 'costs', 'amount_minor')]);

        self::assertSame(['2026-10', '2026-11', '2026-12'], $this->in($this->tool('foresi_client_margin', []), 'structuredContent', 'data', 'months'));
    }

    public function testTheToolsRefuseWithTheCodeOfTheRefusal(): void
    {
        $call = $this->tool('foresi_client_margin', ['from' => '2026-12', 'to' => '2026-11']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame('range_reversed', $this->in($call, 'structuredContent', 'error', 'details', 'reason'));

        $call = $this->tool('foresi_client_margin_cell', ['row' => 'somebody', 'month' => '2026-11']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame(['parameter' => 'row', 'reason' => 'invalid_row'], $this->in($call, 'structuredContent', 'error', 'details'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cells(int $row): array
    {
        return array_values(array_map(fn (mixed $cell): array => [
            'month' => $this->in($cell, 'month'),
            'revenue' => $this->in($cell, 'revenue', 'amount_minor'),
            'costs' => $this->in($cell, 'costs', 'amount_minor'),
            'margin' => $this->in($cell, 'margin', 'amount_minor'),
        ], (array) $this->at('data', 'rows', $row, 'cells')));
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
        $result = $this->in($this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments]), 'result');
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
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->reader, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
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
