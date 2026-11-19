<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * Sales invoices written over REST and MCP: issued with the defaults and the
 * counter of the form, changed with its rules and locks, trashed and restored,
 * behind invoices:write.
 */
final class InvoiceWriteApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('invoices:write')]);
    }

    public function testCreateIssuesWithTheNextNumberAndTheDefaultsOfTheForm(): void
    {
        $this->send('POST', '/api/v1/invoices', $this->fields());

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/invoices/'.$id);
        self::assertSame('invoice', $this->at('data', 'kind'));
        self::assertNotSame('', $this->at('data', 'number'));
        self::assertSame($this->customerId, $this->at('data', 'customer', 'id'));
        self::assertSame('2026-11-02', $this->at('data', 'issue_date'));
        self::assertSame('2026-11-02', $this->at('data', 'tax_point_date'));
        // The due days of a customer with no settings of its own.
        self::assertSame('2026-11-16', $this->at('data', 'due_date'));
        self::assertSame(['amount_minor' => 242_000, 'currency' => 'CZK'], $this->at('data', 'total'));
        self::assertSame('Consulting', $this->at('data', 'description'));
        $number = $this->at('data', 'number');
        $created = $this->json()['data'];

        $this->get('/api/v1/invoices/'.$id, secret: $this->writer);
        self::assertSame($created, $this->json()['data']);

        $this->send('POST', '/api/v1/invoices', $this->fields());
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($number, $this->at('data', 'number'));
    }

    public function testATypedNumberContinuesTheNumberingOfAnotherSystem(): void
    {
        $this->send('POST', '/api/v1/invoices', ['number' => 'OLD-2026-0042'] + $this->fields());

        self::assertResponseStatusCodeSame(201);
        self::assertSame('OLD-2026-0042', $this->at('data', 'number'));

        $this->send('POST', '/api/v1/invoices', ['number' => 'OLD-2026-0042'] + $this->fields());
        $this->assertProblem(409, 'number_taken', ['problems' => ['number: number_taken']]);
    }

    public function testUpdateReplacesTheLinesAndKeepsWhatIsLeftOut(): void
    {
        $id = $this->created();

        $this->send('PATCH', '/api/v1/invoices/'.$id, ['lines' => [['text' => 'Review', 'unit_price' => '500.00']], 'order_number' => 'PO-7']);

        self::assertResponseIsSuccessful();
        self::assertSame(['amount_minor' => 60_500, 'currency' => 'CZK'], $this->at('data', 'total'));
        self::assertSame('PO-7', $this->at('data', 'order_number'));
        self::assertSame('2026-11-16', $this->at('data', 'due_date'));
        $lines = $this->at('data', 'lines');
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $call = $this->tool('foresi_update_invoice', ['invoice_id' => $id, 'due_date' => '2026-11-30']);
        self::assertFalse($call['isError'] ?? false);
        $this->get('/api/v1/invoices/'.$id, secret: $this->writer);
        self::assertSame('2026-11-30', $this->at('data', 'due_date'));
        self::assertSame(['amount_minor' => 60_500, 'currency' => 'CZK'], $this->at('data', 'total'));
        self::assertSame('PO-7', $this->at('data', 'order_number'));
    }

    public function testADeliveredInvoiceKeepsItsNumberButTakesOtherChanges(): void
    {
        $id = $this->created();
        $this->flushed(fn (): mixed => self::getContainer()->get(MarkDelivered::class)->mark($id));

        $this->send('PATCH', '/api/v1/invoices/'.$id, ['number' => 'OTHER-1']);
        $this->assertProblem(409, 'number_locked', ['problems' => ['number: number_locked']]);

        $this->send('PATCH', '/api/v1/invoices/'.$id, ['order_number' => 'PO-8']);
        self::assertResponseIsSuccessful();
        self::assertSame('PO-8', $this->at('data', 'order_number'));
    }

    public function testEveryProblemOfTheRulesIsNamed(): void
    {
        $this->send('POST', '/api/v1/invoices', ['customer_id' => '01940000-0000-7000-8000-000000000001', 'variable_symbol' => 'ABC'] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'variable_symbol', 'reason' => 'variable_symbol_invalid', 'problems' => ['variable_symbol: variable_symbol_invalid', 'customer_id: customer_unknown']]);

        $this->send('POST', '/api/v1/invoices', ['lines' => [['text' => 'Consulting', 'unit_price' => '1.001']]] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'lines[0].unit_price', 'reason' => 'number_invalid']);

        $this->send('POST', '/api/v1/invoices', ['issue_date' => '2026-02-30'] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'issue_date', 'reason' => 'date_invalid']);

        $this->send('PATCH', '/api/v1/invoices/01940000-0000-7000-8000-000000000001', ['order_number' => 'PO-1']);
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/invoices', secret: $this->writer);
        self::assertSame([], $this->ids());
    }

    public function testARetriedCreateWithAKeyIssuesOnce(): void
    {
        $this->send('POST', '/api/v1/invoices', $this->fields(), idempotencyKey: 'invoice-1');
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();
        $this->send('POST', '/api/v1/invoices', $this->fields(), idempotencyKey: 'invoice-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotent-Replayed', 'true');
        self::assertSame($first, $this->json());

        $call = $this->tool('foresi_create_invoice', $this->fields() + ['idempotency_key' => 'invoice-2']);
        $again = $this->tool('foresi_create_invoice', $this->fields() + ['idempotency_key' => 'invoice-2']);
        self::assertFalse($call['isError'] ?? false);
        $data = static fn (array $result): mixed => \is_array($result['structuredContent'] ?? null) ? $result['structuredContent']['data'] ?? null : null;
        self::assertNotNull($data($call));
        self::assertSame($data($call), $data($again));
        self::assertTrue(\is_array($again['structuredContent'] ?? null) && true === ($again['structuredContent']['idempotent_replayed'] ?? null));

        $this->get('/api/v1/invoices', secret: $this->writer);
        self::assertCount(2, $this->ids());
    }

    public function testTrashAndRestoreOverMcp(): void
    {
        $id = $this->created();

        $call = $this->tool('foresi_trash_invoice', ['invoice_id' => $id, 'reason' => 'Issued by mistake.']);
        self::assertFalse($call['isError'] ?? false);
        $this->get('/api/v1/invoices/'.$id, secret: $this->writer);
        self::assertTrue($this->at('data', 'trashed'));

        $this->send('PATCH', '/api/v1/invoices/'.$id, ['order_number' => 'PO-9']);
        $this->assertProblem(409, 'trashed');

        $call = $this->tool('foresi_restore_invoice', ['invoice_id' => $id]);
        self::assertFalse($call['isError'] ?? false);
        $this->get('/api/v1/invoices/'.$id, secret: $this->writer);
        self::assertFalse($this->at('data', 'trashed'));
    }

    public function testAReadTokenCannotWrite(): void
    {
        $reader = $this->issued([ApiScope::from('invoices:read')]);

        $this->send('POST', '/api/v1/invoices', $this->fields(), $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/invoices', secret: $reader);
        self::assertSame([], $this->ids());
    }

    public function testTheFieldsDeclareTheLengthsTheDocumentHolds(): void
    {
        $texts = ['bank_account' => 34, 'variable_symbol' => 10, 'constant_symbol' => Invoice::MAX_CONSTANT_SYMBOL_LENGTH, 'order_number' => Invoice::MAX_ORDER_NUMBER_LENGTH, 'description' => 2000, 'text_before' => 2000, 'text_after' => 2000];

        self::assertSame(['number' => Invoice::MAX_NUMBER_LENGTH, 'currency' => 3] + $texts, $this->lengths('invoices.create'));
        self::assertSame(['number' => Invoice::MAX_NUMBER_LENGTH] + $texts, $this->lengths('invoices.update'));
    }

    /**
     * @return array<string, int> the longest value of each field that declares one, in the order of the fields
     */
    private function lengths(string $operation): array
    {
        $properties = InputSchema::of(self::getContainer()->get(Operations::class)->get($operation)->fields)['properties'];
        self::assertIsArray($properties);
        $lengths = [];
        foreach ($properties as $wire => $property) {
            if (\is_string($wire) && \is_array($property) && isset($property['maxLength']) && \is_int($property['maxLength'])) {
                $lengths[$wire] = $property['maxLength'];
            }
        }

        return $lengths;
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(): array
    {
        return [
            'customer_id' => $this->customerId,
            'issue_date' => '2026-11-02',
            'lines' => [['text' => 'Consulting', 'unit_price' => '1000.00', 'quantity' => '2', 'unit' => 'h']],
        ];
    }

    private function created(): string
    {
        $this->send('POST', '/api/v1/invoices', $this->fields());
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null, ?string $idempotencyKey = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ] + (null === $idempotencyKey ? [] : ['HTTP_IDEMPOTENCY_KEY' => $idempotencyKey]), content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
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
        $result = $this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments])['result'] ?? null;
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
