<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Integration\Invoicing;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Saved views over REST and MCP (spec D13): the lists and views read under
 * views:read, written under views:write, and the documents read through a view
 * or a filter, which also needs invoices:read.
 */
final class ViewApiTest extends ApiTestCase
{
    use IssuesInvoices;

    private const array DUE_THIS_WEEK = ['field' => 'due_date', 'op' => 'is', 'value' => ['relative' => 'next_n_days', 'n' => 7]];

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-17 10:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('views:write'), ApiScope::from('invoices:read')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testThroughMcpClaudeListsViewsCreatesASharedOneAndListsTheInvoicesItHolds(): void
    {
        [$soon] = $this->flushed(fn (): array => [
            $this->invoice('2026-11-03', '2026-11-20'),
            $this->invoice('2026-11-04', '2026-12-15'),
            $this->invoice('2026-10-01', '2026-11-10'),
        ]);

        $views = $this->tool('foresi_list_views', ['list' => 'invoicing.invoices']);
        self::assertFalse($this->in($views, 'isError'));
        $keys = array_column($this->rows($this->in($views, 'structuredContent', 'data', 'views')), 'system_key');
        self::assertContains('invoicing.invoices.overdue', $keys);

        $created = $this->tool('foresi_create_view', ['list_key' => 'invoicing.invoices', 'name' => 'Due this week', 'visibility' => 'shared', 'conditions' => [self::DUE_THIS_WEEK]]);
        self::assertFalse($this->in($created, 'isError'), (string) json_encode($created));
        $view = $this->in($created, 'structuredContent', 'data');
        self::assertIsArray($view);
        $id = $view['id'];
        self::assertIsString($id);
        self::assertSame(['Due this week', 'shared', 'invoicing.invoices', true], [$view['name'], $view['visibility'], $view['list'], $view['may_edit']]);
        self::assertSame(['match' => 'all', 'conditions' => [self::DUE_THIS_WEEK]], $view['filters']);

        $listed = $this->tool('foresi_search_invoices', ['view_id' => $id]);
        self::assertFalse($this->in($listed, 'isError'), (string) json_encode($listed));
        self::assertSame([$soon->id()], array_column($this->rows($this->in($listed, 'structuredContent', 'data', 'items')), 'id'));
        self::assertSame($id, $this->in($listed, 'structuredContent', 'data', 'applied_view', 'id'));

        $this->get('/api/v1/invoices', ['view' => $id], $this->writer);
        self::assertSame([$soon->id()], $this->ids());
        self::assertSame('Due this week', $this->at('applied_view', 'name'));
        $this->get('/api/v1/views/'.$id.'/count', secret: $this->writer);
        self::assertSame(['view_id' => $id, 'list' => 'invoicing.invoices', 'count' => 1], $this->at('data'));
    }

    public function testTheListsDescribeTheirFieldsColumnsAndDateValues(): void
    {
        $this->get('/api/v1/lists');

        self::assertResponseIsSuccessful();
        $invoices = array_column($this->rows($this->at('data')), null, 'key')['invoicing.invoices'] ?? null;
        self::assertIsArray($invoices);
        $fields = array_column($this->rows($invoices['fields']), null, 'key');
        self::assertSame(['key' => 'due_date', 'type' => 'date', 'label' => 'Due', 'operators' => ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between', 'is_empty', 'is_not_empty'], 'options' => [], 'quick_pick' => 'months', 'multi_pick' => false], $fields['due_date'] ?? null);
        self::assertSame(['paid', 'unpaid', 'to_refund'], $this->in($fields, 'payment', 'options'));
        // The contacts' fields are their data source's attributes (spec DS1).
        $contacts = array_column($this->rows($this->at('data')), null, 'key')['party.contacts'] ?? null;
        self::assertIsArray($contacts);
        $fields = array_column($this->rows($contacts['fields']), null, 'key');
        self::assertSame(['key' => 'email', 'type' => 'text', 'label' => 'E-mail', 'operators' => ['contains', 'does_not_contain', 'is', 'is_not', 'is_empty', 'is_not_empty'], 'options' => [], 'quick_pick' => 'sheet', 'multi_pick' => false], $fields['email'] ?? null);
        self::assertSame(['has_any_of', 'has_all_of', 'has_none_of', 'is_empty', 'is_not_empty'], $this->in($fields, 'roles', 'operators'));
        $columns = $this->rows($invoices['columns']);
        self::assertNotSame([], $columns);
        self::assertSame(['key', 'label', 'sortable', 'default', 'locked'], array_keys($columns[0]));
        $byKey = array_column($columns, null, 'key');
        self::assertTrue($this->in($byKey, 'number', 'sortable'));
        self::assertTrue($this->in($byKey, 'due_date', 'sortable'));
        self::assertArrayNotHasKey('dueDate', $byKey);
        self::assertFalse($this->in($byKey, 'status', 'sortable'));
        self::assertSame('invoicing.invoices.overdue', $this->in($invoices, 'default_views', 3));
        $counted = $this->at('date_values', 'relative_counted');
        self::assertIsArray($counted);
        self::assertContains('next_n_days', $counted);
    }

    public function testAViewIsReadByItsIdOrSystemKeyAndCounted(): void
    {
        $this->flushed(fn (): array => [$this->invoice('2026-10-01', '2026-11-10'), $this->invoice('2026-11-04', '2026-12-15')]);

        $this->get('/api/v1/views/invoicing.invoices.overdue');
        self::assertResponseIsSuccessful();
        self::assertSame(['invoicing.invoices.overdue', 'shared', null, false], [$this->at('data', 'system_key'), $this->at('data', 'visibility'), $this->at('data', 'owner_id'), $this->at('data', 'may_edit')]);
        self::assertSame('Overdue', $this->at('data', 'name'));
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $this->get('/api/v1/views/'.$id.'/count');
        self::assertSame(1, $this->at('data', 'count'));
        $this->get('/api/v1/views', ['list' => 'invoicing.invoices']);
        self::assertContains($id, $this->ids());
    }

    public function testAFilterIsJoinedWithTheOtherParametersByAnd(): void
    {
        [$past, $soon] = $this->flushed(fn (): array => [$this->invoice('2026-10-01', '2026-11-10'), $this->invoice('2026-11-03', '2026-11-20'), $this->invoice('2026-11-04', '2026-12-15')]);
        $before = json_encode(['conditions' => [['field' => 'due_date', 'op' => 'before', 'value' => '2026-12-01']]], \JSON_THROW_ON_ERROR);

        $this->get('/api/v1/invoices', ['filter' => $before]);
        self::assertSame([$soon->id(), $past->id()], $this->ids());
        self::assertNull($this->at('applied_view'));
        $this->get('/api/v1/invoices', ['filter' => $before, 'issued_from' => '2026-11-01']);
        self::assertSame([$soon->id()], $this->ids());
        $this->get('/api/v1/invoices', ['filter' => $before, 'view' => 'invoicing.invoices.overdue']);
        self::assertSame([$past->id()], $this->ids());
        $this->get('/api/v1/invoices', ['filter' => $before, 'state' => 'overdue']);
        self::assertSame([$past->id()], $this->ids());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badFilters(): iterable
    {
        yield 'not JSON' => [['filter' => '{conditions'], 'filter'];
        yield 'no object' => [['filter' => '"due"'], 'filter'];
        yield 'an unknown field' => [['filter' => '{"conditions": [{"field": "colour", "op": "is", "value": "red"}]}'], 'filter.conditions[0].field'];
        yield 'an operator the type lacks' => [['filter' => '{"conditions": [{"field": "due_date", "op": "contains", "value": "x"}]}'], 'filter.conditions[0].op'];
        yield 'an unknown relative date' => [['filter' => '{"conditions": [{"field": "due_date", "op": "is", "value": {"relative": "someday"}}]}'], 'filter.conditions[0].value.relative'];
        yield 'a field of another kind' => [['filter' => '{"conditions": [{"field": "settlement", "op": "is", "value": "settled"}]}', 'kind' => 'invoice'], 'filter.conditions[0].field'];
        yield 'a view of another kind' => [['view' => 'invoicing.advances.all', 'kind' => 'invoice'], 'view'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('badFilters')]
    public function testABadFilterIsRefusedWithItsPath(array $query, string $parameter): void
    {
        $this->get('/api/v1/invoices', $query);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter]);
    }

    public function testABadConditionOfAWriteIsRefusedWithItsPathOverRestAndMcp(): void
    {
        $this->send('POST', '/api/v1/views', ['list_key' => 'invoicing.invoices', 'name' => 'Broken', 'conditions' => [['field' => 'due_date', 'op' => 'contains', 'value' => 'x']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'conditions[0].op', 'reason' => 'filter_invalid']);
        $this->send('POST', '/api/v1/views', ['list_key' => 'invoicing.invoices', 'name' => 'Broken', 'conditions' => ['due_date']]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'conditions']);

        $refused = $this->tool('foresi_create_view', ['list_key' => 'invoicing.invoices', 'name' => 'Broken', 'conditions' => [['field' => 'due_date', 'op' => 'is', 'value' => ['relative' => 'someday']]]]);
        self::assertTrue($this->in($refused, 'isError'));
        self::assertSame('conditions[0].value.relative', $this->in($refused, 'structuredContent', 'error', 'details', 'parameter'));
    }

    public function testAnUnknownViewIsNotFound(): void
    {
        $this->get('/api/v1/invoices', ['view' => '0190e0b4-0000-7000-8000-000000000000']);
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/views/invoicing.invoices.nothing');
        $this->assertProblem(404, 'not_found');
    }

    public function testViewsUpdateAndDeleteUnderViewsWrite(): void
    {
        $this->send('POST', '/api/v1/views', ['list_key' => 'invoicing.invoices', 'name' => 'Mine', 'conditions' => [self::DUE_THIS_WEEK], 'columns' => ['number', ['key' => 'customer', 'width' => 200]]]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/views/'.$id);
        self::assertSame('private', $this->at('data', 'visibility'));

        $this->send('PATCH', '/api/v1/views/'.$id, ['name' => 'Due soon', 'visibility' => 'shared']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Due soon', 'shared'], [$this->at('data', 'name'), $this->at('data', 'visibility')]);
        $this->send('PATCH', '/api/v1/views/'.$id, ['group_by' => 'customer']);
        self::assertResponseIsSuccessful();
        self::assertSame('customer', $this->at('data', 'display', 'group_by'), 'what the rows are grouped by goes with the display');

        // An older column key is stored under the key it names, a column named twice once, and an unknown one as it is.
        $this->send('PATCH', '/api/v1/views/'.$id, ['columns' => ['number', ['key' => 'dueDate', 'width' => 120], 'due_date', 'gone']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['columns' => [['key' => 'number'], ['key' => 'due_date', 'width' => 120], ['key' => 'gone']], 'group_by' => 'customer'], $this->at('data', 'display'), 'changing the columns keeps the grouping');

        $this->send('DELETE', '/api/v1/views/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $id, 'deleted' => true], $this->at('data'));
        $this->send('DELETE', '/api/v1/views/invoicing.invoices.overdue');
        $this->assertProblem(409, 'default_view');
    }

    public function testEachSurfaceNeedsItsScope(): void
    {
        $invoicesOnly = $this->issued([ApiScope::from('invoices:read')]);
        $viewsOnly = $this->issued([ApiScope::from('views:read')]);

        $this->get('/api/v1/views', ['list' => 'invoicing.invoices'], $invoicesOnly);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/lists', secret: $invoicesOnly);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/invoices', ['view' => 'invoicing.invoices.overdue'], $invoicesOnly);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/invoices', ['filter' => '{"conditions": []}'], $invoicesOnly);
        self::assertResponseIsSuccessful();

        $this->get('/api/v1/views/invoicing.invoices.overdue', secret: $viewsOnly);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/views/invoicing.invoices.overdue/count', secret: $viewsOnly);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/invoices', ['view' => 'invoicing.invoices.overdue'], $viewsOnly);
        $this->assertProblem(403, 'insufficient_scope');

        $this->client->request('POST', '/api/v1/views', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret, 'CONTENT_TYPE' => 'application/json'], content: '{"list_key": "invoicing.invoices", "name": "Read only"}');
        $this->assertProblem(403, 'insufficient_scope');
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

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(mixed $rows): array
    {
        self::assertIsList($rows);
        $checked = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $checked[] = $row;
        }

        return $checked;
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
