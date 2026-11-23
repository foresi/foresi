<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Recurring invoices over REST and MCP: previewed without saving, made from an
 * invoice with a line left out, read with their next issues, changed under the
 * version of the form, paused, resumed and ended, behind invoices:read and
 * invoices:write.
 */
final class RecurringInvoiceApiTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-11-20 10:00:00');
        $this->writer = $this->issued([ApiScope::from('invoices:read'), ApiScope::from('invoices:write')]);
    }

    public function testFromAnInvoiceWithoutOneLineIssuesOnlyAndShowsItsNextIssues(): void
    {
        $invoiceId = $this->invoice();

        $preview = $this->tool('foresi_preview_recurring_invoice', ['from_invoice_id' => $invoiceId, 'delivery' => 'review', 'first_issue_on' => '2026-12-01', 'count' => 3]);
        self::assertFalse($preview['isError'] ?? false);
        $draft = $this->data($preview);
        self::assertTrue($draft['saves']);
        self::assertSame($this->customerId, $this->field($draft, 'customer')['id'] ?? null);
        self::assertSame(14, $draft['due_days'], 'the due days of the invoice');
        self::assertSame(['Hosting', 'Development'], array_column($this->rows($draft, 'lines'), 'text'));
        self::assertSame(['2026-12-01', '2027-01-01', '2027-02-01'], array_column($this->rows($draft, 'next_issues'), 'issue_date'));
        $this->get('/api/v1/recurring-invoices', secret: $this->writer);
        self::assertSame(0, $this->at('total'), 'a preview saves nothing');

        $kept = array_values(array_filter($this->rows($draft, 'lines'), static fn (array $line): bool => 'Development' !== ($line['text'] ?? null)));
        $this->send('POST', '/api/v1/recurring-invoices', ['from_invoice_id' => $invoiceId, 'lines' => $kept, 'delivery' => 'review', 'first_issue_on' => '2026-12-01']);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/recurring-invoices/'.$id);
        self::assertSame('review', $this->at('data', 'delivery'));
        self::assertSame('active', $this->at('data', 'state'));
        self::assertSame('2026-12-01', $this->at('data', 'next_issue_on'));
        self::assertSame(['month', 1, 'never'], [$this->at('data', 'schedule', 'repeat'), $this->at('data', 'schedule', 'every'), $this->at('data', 'schedule', 'end')]);
        self::assertSame([['text' => 'Hosting', 'unit_price' => '1000.00', 'quantity' => '1.0000', 'unit' => 'month', 'price_type' => 'without_vat', 'vat' => 'base', 'discount' => '0.00', 'supply_code' => null]], $this->at('data', 'lines'));
        self::assertSame(['2026-12-01', '2026-12-01', '2026-12-15'], [$this->at('data', 'next_issues', 0, 'issue_date'), $this->at('data', 'next_issues', 0, 'tax_point_date'), $this->at('data', 'next_issues', 0, 'due_date')]);
        self::assertSame('PO-1', $this->at('data', 'order_number'));

        $listed = $this->data($this->tool('foresi_list_recurring_invoices', []));
        self::assertSame([$id], array_column($this->rows($listed, 'items'), 'id'));
        $read = $this->data($this->tool('foresi_get_recurring_invoice', ['id' => $id]));
        self::assertSame(['Hosting'], array_column($this->rows($read, 'lines'), 'text'));
        self::assertCount(5, $this->rows($read, 'next_issues'));
    }

    public function testAChangeNeedsTheVersionItWasReadIn(): void
    {
        $id = $this->created();
        $this->get('/api/v1/recurring-invoices/'.$id, secret: $this->writer);
        $version = $this->at('data', 'version');
        self::assertIsInt($version);

        $call = $this->tool('foresi_update_recurring_invoice', ['recurring_invoice_id' => $id, 'version' => $version, 'due_days' => 30, 'delivery' => 'send', 'repeat' => 'year', 'idempotency_key' => 'update-1']);
        self::assertFalse($call['isError'] ?? false);
        $data = $this->data($call);
        self::assertSame([30, 'send', 'year'], [$data['due_days'], $data['delivery'], $this->field($data, 'schedule')['repeat'] ?? null]);
        self::assertSame(['Hosting'], array_column($this->rows($data, 'lines'), 'text'), 'the lines stay when left out');

        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $version, 'due_days' => 10]);
        $this->assertProblem(409, 'changed_meanwhile');
    }

    public function testPauseResumeAndEnd(): void
    {
        $id = $this->created();

        $this->send('POST', '/api/v1/recurring-invoices/'.$id.'/pause');
        self::assertResponseIsSuccessful();
        self::assertSame('paused', $this->at('data', 'state'));
        self::assertSame([], $this->at('data', 'next_issues'));

        $call = $this->tool('foresi_resume_recurring_invoice', ['recurring_invoice_id' => $id, 'idempotency_key' => 'resume-1']);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame('active', $this->data($call)['state']);

        $this->get('/api/v1/recurring-invoices', ['state' => 'active'], secret: $this->writer);
        self::assertSame([$id], $this->ids());

        $this->send('POST', '/api/v1/recurring-invoices/'.$id.'/end');
        self::assertResponseIsSuccessful();
        self::assertSame('ended', $this->at('data', 'state'));
        $version = $this->at('data', 'version');

        $this->send('POST', '/api/v1/recurring-invoices/'.$id.'/resume');
        $this->assertProblem(409, 'ended');
        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $version, 'due_days' => 10]);
        $this->assertProblem(409, 'ended');
    }

    public function testWhatCannotBeSavedIsNamed(): void
    {
        $this->send('POST', '/api/v1/recurring-invoices', ['delivery' => 'review', 'lines' => [['text' => 'Hosting', 'unit_price' => '1000.00']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'customer_id', 'reason' => 'customer_missing']);

        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(['first_issue_on' => '2026-11-01']));
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'first_issue_on', 'reason' => 'first_issue_past']);

        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(['end' => 'after_count']));
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'ends_after', 'reason' => 'end_after_invalid']);

        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(['first_issue_on' => '2026-11-01']));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->at('data', 'saves'));
        $problems = $this->at('data', 'problems');
        self::assertIsArray($problems);
        self::assertStringStartsWith('recurrence.firstIssueOn: first_issue_past', \is_string($problems[0] ?? null) ? $problems[0] : '');

        $this->get('/api/v1/recurring-invoices/01940000-0000-7000-8000-000000000001', secret: $this->writer);
        $this->assertProblem(404, 'not_found');

        // Reading and previewing need invoices:read only; saving needs invoices:write.
        $reader = $this->issued([ApiScope::from('invoices:read')]);
        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(), secret: $reader);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(), secret: $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/recurring-invoices', secret: $this->writer);
        self::assertSame([], $this->ids());
    }

    public function testARetriedCreateWithAKeySavesOnce(): void
    {
        $first = $this->tool('foresi_create_recurring_invoice', $this->fields() + ['idempotency_key' => 'recurring-1']);
        $again = $this->tool('foresi_create_recurring_invoice', $this->fields() + ['idempotency_key' => 'recurring-1']);

        self::assertFalse($first['isError'] ?? false);
        self::assertSame($this->data($first)['id'], $this->data($again)['id']);
        $this->get('/api/v1/recurring-invoices', secret: $this->writer);
        self::assertCount(1, $this->ids());
    }

    /**
     * An issued invoice of two lines.
     */
    private function invoice(): string
    {
        $this->send('POST', '/api/v1/invoices', [
            'customer_id' => $this->customerId,
            'issue_date' => '2026-11-02',
            'order_number' => 'PO-1',
            'lines' => [
                ['text' => 'Hosting', 'unit_price' => '1000.00', 'unit' => 'month'],
                ['text' => 'Development', 'unit_price' => '5000.00', 'quantity' => '2', 'unit' => 'h'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customerId,
            'delivery' => 'review',
            'first_issue_on' => '2026-12-01',
            'lines' => [['text' => 'Hosting', 'unit_price' => '1000.00', 'unit' => 'month']],
        ];
    }

    private function created(): string
    {
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields());
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<mixed> $result
     *
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $data = \is_array($result['structuredContent'] ?? null) ? $result['structuredContent']['data'] ?? null : null;
        self::assertIsArray($data, json_encode($result, \JSON_THROW_ON_ERROR));

        return array_filter($data, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    private function field(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $rows = [];
        foreach ($this->field($data, $key) as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
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
        $result = $this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments])['result'] ?? null;
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
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
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
}
