<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Invoicing;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Margin\Domain\RebillLink;
use Foresi\Margin\Domain\RebillPairing;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Rebilling over REST and MCP on the planned items of Cash flow and the
 * invoices and recurring templates of Invoicing. Who may work with the margin
 * at all is in tests/Margin/Infrastructure. Today is 10 December 2026.
 */
final class RebillApiTest extends ApiTestCase
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
    private string $supplier;
    private string $item;
    private string $invoiceLine;
    private string $invoiceId;
    private string $templateLine;
    private string $templateId;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('margin:write'), ApiScope::from('margin:read'), ApiScope::from('cashflow:write'), ApiScope::from('invoices:write'), ApiScope::from('received_documents:write')]);
        $this->supplier = $this->flushed(function (): string {
            $party = new Party(PartyKind::Person, 'Cloud Host', null, null, '+420 000 000 001');
            $this->em()->persist($party);

            return $party->id();
        });
        $this->item = $this->planned();
        // The customer bears the whole cost from December on.
        $this->send('PUT', '/api/v1/margin/planned-items/'.$this->item.'/allocation', ['allocation' => ['kind' => 'whole', 'shares' => [['recipient' => $this->customerId]]], 'from_month' => '2026-12']);
        self::assertResponseIsSuccessful();
        $this->invoiceId = $this->invoice();
        $this->get('/api/v1/invoices/'.$this->invoiceId, secret: $this->writer);
        $this->invoiceLine = $this->idAt('data', 'lines', 0, 'id');
        $this->send('POST', '/api/v1/recurring-invoices', ['from_invoice_id' => $this->invoiceId, 'delivery' => 'review', 'first_issue_on' => '2027-01-01']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $this->templateId = $this->idAt('data', 'id');
        $this->templateLine = $this->idAt('data', 'lines', 0, 'id');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testALinkIsSetChangedAndRemoved(): void
    {
        $this->send('PUT', $this->linkPath(), ['planned_item_id' => $this->item]);

        self::assertResponseIsSuccessful();
        self::assertSame([$this->templateLine, $this->item, 'Cloud Host', '2026-12-10', []], [
            $this->at('data', 'recurring_line_id'), $this->at('data', 'planned_item_id'), $this->at('data', 'planned_item_name'), $this->at('data', 'created_on'), $this->at('data', 'warnings'),
        ]);
        self::assertSame(1, $this->em()->getRepository(RebillLink::class)->count([]));

        // A cost whose mapping gives the customer no share still links, with a warning.
        $other = $this->planned(['name' => 'Voice API']);
        $this->send('PUT', $this->linkPath(), ['planned_item_id' => $other]);
        self::assertResponseIsSuccessful();
        self::assertSame([$other, 'Voice API', ['customer_not_a_recipient']], [$this->at('data', 'planned_item_id'), $this->at('data', 'planned_item_name'), $this->at('data', 'warnings')]);
        self::assertSame(1, $this->em()->getRepository(RebillLink::class)->count([]));

        $this->send('PUT', $this->linkPath(), ['planned_item_id' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->templateLine, null, null, null, []], [$this->at('data', 'recurring_line_id'), $this->at('data', 'planned_item_id'), $this->at('data', 'planned_item_name'), $this->at('data', 'created_on'), $this->at('data', 'warnings')]);
        self::assertSame(0, $this->em()->getRepository(RebillLink::class)->count([]));
    }

    public function testTheLineIdsComeFromTheApiAndAnIssuedLineNamesItsTemplateLine(): void
    {
        $this->get('/api/v1/invoices/'.$this->invoiceId, secret: $this->writer);
        self::assertNull($this->at('data', 'lines', 0, 'source_line_id'), 'an invoice written by hand has no template line');
        $this->get('/api/v1/recurring-invoices/'.$this->templateId, secret: $this->writer);
        self::assertSame($this->templateLine, $this->at('data', 'lines', 0, 'id'));

        $issued = self::getContainer()->get(\Foresi\Invoicing\Application\IssueRecurringOccurrence::class)->issue($this->templateId, new \DateTimeImmutable('2027-01-01'));
        self::assertNotNull($issued);
        $this->em()->clear();
        $this->get('/api/v1/invoices/'.$issued->id(), secret: $this->writer);
        self::assertSame($this->templateLine, $this->at('data', 'lines', 0, 'source_line_id'));
    }

    public function testALinkIsRefusedWithItsCode(): void
    {
        $this->send('PUT', $this->linkPath(), ['planned_item_id' => Uuid::v7()->toRfc4122()]);
        $this->assertProblem(404, 'unknown_planned_item');
        $this->send('PUT', $this->linkPath(), []);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'planned_item_id']);
        $this->send('PUT', $this->linkPath(Uuid::v7()->toRfc4122()), ['planned_item_id' => $this->item]);
        $this->assertProblem(404, 'unknown_template_line');
        $income = $this->planned(['direction' => 'in', 'category' => 'revenue', 'name' => 'Retainer']);
        $this->send('PUT', $this->linkPath(), ['planned_item_id' => $income]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'planned_item_id', 'reason' => 'not_an_expense']);

        self::assertSame(0, $this->em()->getRepository(RebillLink::class)->count([]));
    }

    public function testAnInvoiceLineIsPairedByHandAndUnpaired(): void
    {
        $this->send('POST', '/api/v1/margin/rebills', ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05']);

        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame([$this->invoiceLine, $this->invoiceId, $this->item, 'Cloud Host', '2026-12-05', true], [
            $this->at('data', 'invoice_line_id'), $this->at('data', 'invoice_id'), $this->at('data', 'planned_item_id'), $this->at('data', 'planned_item_name'), $this->at('data', 'occurrence_on'), $this->at('data', 'manual'),
        ]);
        self::assertSame(1, $this->em()->getRepository(RebillPairing::class)->count([]));

        $this->send('POST', '/api/v1/margin/rebills', ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'invoice_line_id', 'reason' => 'already_paired']);

        $this->send('DELETE', '/api/v1/margin/rebills/'.$this->invoiceLine);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->invoiceLine, true], [$this->at('data', 'invoice_line_id'), $this->at('data', 'unpaired')]);
        self::assertSame(0, $this->em()->getRepository(RebillPairing::class)->count([]));
        $this->send('DELETE', '/api/v1/margin/rebills/'.$this->invoiceLine);
        $this->assertProblem(404, 'unknown_pairing');
    }

    public function testAPairingIsRefusedWithItsCode(): void
    {
        $pair = ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05'];

        $this->send('POST', '/api/v1/margin/rebills', ['occurrence_on' => 'December 5'] + $pair);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'occurrence_on', 'reason' => 'invalid_day']);
        $this->send('POST', '/api/v1/margin/rebills', ['occurrence_on' => '2026-12-06'] + $pair);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'occurrence_on', 'reason' => 'not_an_occurrence']);
        $this->send('POST', '/api/v1/margin/rebills', ['invoice_line_id' => Uuid::v7()->toRfc4122()] + $pair);
        $this->assertProblem(404, 'unknown_invoice_line');
        $this->send('POST', '/api/v1/margin/rebills', ['planned_item_id' => Uuid::v7()->toRfc4122()] + $pair);
        $this->assertProblem(404, 'unknown_planned_item');
        $income = $this->planned(['direction' => 'in', 'category' => 'revenue', 'name' => 'Retainer']);
        $this->send('POST', '/api/v1/margin/rebills', ['planned_item_id' => $income] + $pair);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'planned_item_id', 'reason' => 'not_an_expense']);
        // The customer has no share of a cost with no mapping.
        $this->send('POST', '/api/v1/margin/rebills', ['planned_item_id' => $this->planned(['name' => 'Voice API'])] + $pair);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'invoice_line_id', 'reason' => 'customer_not_a_recipient']);
        $this->send('POST', '/api/v1/margin/rebills', ['invoice_line_id' => $this->invoiceLine]);
        $this->assertProblem(400, 'invalid_parameter');

        self::assertSame(0, $this->em()->getRepository(RebillPairing::class)->count([]));
    }

    public function testACostPaidAndNotRebilledIsListedUntilItsLineIsPaired(): void
    {
        $this->send('PUT', $this->linkPath(), ['planned_item_id' => $this->item]);
        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_type' => 'received_document', 'subject_id' => $this->receivedDocument(), 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05']);
        self::assertResponseIsSuccessful();

        $this->get('/api/v1/margin/unrebilled', ['from' => '2026-12', 'to' => '2026-12'], $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame([['Cloud Host', $this->item, '2026-12-05', '2026-12', $this->customerId, $this->templateLine]], array_map(
            fn (mixed $row): array => [$this->in($row, 'label'), $this->in($row, 'planned_item_id'), $this->in($row, 'occurrence_on'), $this->in($row, 'month'), $this->in($row, 'recipient_id'), $this->in($row, 'recurring_line_id')],
            (array) $this->at('data'),
        ));
        self::assertIsString($this->at('data', 0, 'template_id'));
        self::assertIsString($this->at('data', 0, 'template_url'));

        // The table and the cell carry it.
        $this->get('/api/v1/margin', ['from' => '2026-12', 'to' => '2026-12'], $this->writer);
        self::assertSame([1, 1, 1], [$this->at('data', 'rows', 0, 'cells', 0, 'unrebilled'), $this->at('data', 'rows', 0, 'total', 'unrebilled'), $this->at('data', 'total', 'unrebilled')]);
        $this->get('/api/v1/margin/cell', ['row' => 'client:'.$this->customerId, 'month' => '2026-12'], $this->writer);
        self::assertSame([$this->item, '2026-12-05'], [$this->at('data', 'unrebilled', 0, 'planned_item_id'), $this->at('data', 'unrebilled', 0, 'occurrence_on')]);

        // A month outside the range is not listed, and neither is a month of an earlier range.
        $this->get('/api/v1/margin/unrebilled', ['from' => '2026-10', 'to' => '2026-11'], $this->writer);
        self::assertSame([], $this->at('data'));

        $this->send('POST', '/api/v1/margin/rebills', ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05']);
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/margin/unrebilled', ['from' => '2026-12', 'to' => '2026-12'], $this->writer);
        self::assertSame([], $this->at('data'));

        $this->send('DELETE', '/api/v1/margin/rebills/'.$this->invoiceLine);
        $this->get('/api/v1/margin/unrebilled', secret: $this->writer);
        self::assertCount(1, (array) $this->at('data'), 'the range defaults to the last three months');
    }

    public function testTheUnrebilledRangeIsValidated(): void
    {
        $this->get('/api/v1/margin/unrebilled', ['from' => '2026-13'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'from', 'reason' => 'invalid_month']);
        $this->get('/api/v1/margin/unrebilled', ['from' => '2026-12', 'to' => '2026-10'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'range_reversed']);
        $this->get('/api/v1/margin/unrebilled', ['from' => '2020-01', 'to' => '2026-12'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'range_too_long']);
    }

    public function testTheToolsDoWhatTheEndpointsDoAndARepeatedKeyActsOnce(): void
    {
        $arguments = ['recurring_line_id' => $this->templateLine, 'planned_item_id' => $this->item, 'idempotency_key' => 'link-1'];
        $set = $this->tool('foresi_set_rebill_link', $arguments);
        self::assertFalse($set['isError'] ?? false);
        self::assertSame([$this->item, 'Cloud Host'], [$this->in($set, 'structuredContent', 'data', 'planned_item_id'), $this->in($set, 'structuredContent', 'data', 'planned_item_name')]);
        self::assertTrue($this->in($this->tool('foresi_set_rebill_link', $arguments), 'structuredContent', 'idempotent_replayed'));
        self::assertSame(1, $this->em()->getRepository(RebillLink::class)->count([]));

        $pair = ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05', 'idempotency_key' => 'pair-1'];
        $paired = $this->tool('foresi_pair_rebill_line', $pair);
        self::assertFalse($paired['isError'] ?? false);
        self::assertTrue($this->in($paired, 'structuredContent', 'data', 'manual'));
        self::assertTrue($this->in($this->tool('foresi_pair_rebill_line', $pair), 'structuredContent', 'idempotent_replayed'));
        self::assertSame(1, $this->em()->getRepository(RebillPairing::class)->count([]));

        $list = $this->tool('foresi_list_unrebilled_costs', ['from' => '2026-12', 'to' => '2026-12']);
        self::assertFalse($list['isError'] ?? false);
        self::assertSame([], $this->in($list, 'structuredContent', 'data'));

        $unpaired = $this->tool('foresi_unpair_rebill_line', ['invoice_line_id' => $this->invoiceLine, 'idempotency_key' => 'unpair-1']);
        self::assertFalse($unpaired['isError'] ?? false);
        self::assertSame(0, $this->em()->getRepository(RebillPairing::class)->count([]));
    }

    public function testTheToolsRefuseWithTheCodeOfTheRefusal(): void
    {
        $call = $this->tool('foresi_pair_rebill_line', ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-06', 'idempotency_key' => 'bad-1']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame(['parameter' => 'occurrence_on', 'reason' => 'not_an_occurrence'], $this->in($call, 'structuredContent', 'error', 'details'));

        $call = $this->tool('foresi_unpair_rebill_line', ['invoice_line_id' => $this->invoiceLine, 'idempotency_key' => 'bad-2']);
        self::assertSame('unknown_pairing', $this->in($call, 'structuredContent', 'error', 'code'));

        $call = $this->tool('foresi_list_unrebilled_costs', ['from' => 'soon']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
    }

    private function linkPath(?string $lineId = null): string
    {
        return '/api/v1/margin/recurring-lines/'.($lineId ?? $this->templateLine).'/rebills';
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

    private function receivedDocument(): string
    {
        $this->send('POST', '/api/v1/received-documents', [
            'kind' => 'tax_invoice',
            'supplier_id' => $this->supplier,
            'supplier_number' => 'CH-1',
            'issue_date' => '2026-12-02',
            'due_date' => '2026-12-16',
            'received_date' => '2026-12-03',
            'total' => '12100.00',
            'vat_recap' => ['21:10000.00:2100.00'],
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    private function invoice(): string
    {
        $this->send('POST', '/api/v1/invoices', ['customer_id' => $this->customerId, 'issue_date' => '2026-12-02', 'lines' => [['text' => 'Hosting', 'unit_price' => '1000.00', 'unit' => 'month']]]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    private function idAt(string|int ...$path): string
    {
        $id = $this->at(...$path);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body = []): void
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
