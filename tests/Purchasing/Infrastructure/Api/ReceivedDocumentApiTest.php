<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Infrastructure\Api;

use App\Identity\Domain\ApiScope;
use App\Shared\Infrastructure\Operation\InputSchema;
use App\Shared\Infrastructure\Operation\Operations;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * Received documents over REST and MCP (decision C4 of the cash flow spec):
 * recorded from fields with no file, listed by what is unpaid and due, read
 * with their payments, paid and trashed, behind the received_documents scopes.
 */
final class ReceivedDocumentApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('received_documents:write')]);
    }

    public function testRecordFromFieldsAnswers201WithTheDetail(): void
    {
        $this->send('POST', '/api/v1/received-documents', $this->fields());

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/received-documents/'.$id);
        self::assertSame('recorded', $this->at('data', 'state'));
        self::assertSame(['amount_minor' => 121_000, 'currency' => 'CZK'], $this->at('data', 'total'));
        self::assertSame(['amount_minor' => 121_000, 'currency' => 'CZK'], $this->at('data', 'remaining'));
        self::assertFalse($this->at('data', 'has_original_file'));
        self::assertSame('21', $this->at('data', 'vat_recap', 0, 'rate'));
        self::assertSame($this->customerId, $this->at('data', 'supplier', 'id'));
        self::assertSame('2026-11-03', $this->at('data', 'received_date'));
        self::assertSame(['amount_minor' => 100_000, 'currency' => 'CZK'], $this->at('data', 'vat_recap', 0, 'base'));
        self::assertSame(['amount_minor' => 21_000, 'currency' => 'CZK'], $this->at('data', 'vat_recap', 0, 'vat'));
        $created = $this->json()['data'];

        $this->get('/api/v1/received-documents/'.$id, secret: $this->writer);
        self::assertSame($created, $this->json()['data']);
    }

    public function testWhatTheApiCannotReadIsRefusedByItsField(): void
    {
        $this->send('POST', '/api/v1/received-documents', ['issue_date' => '2026-13-01'] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'issue_date', 'reason' => 'date_invalid']);

        $this->send('POST', '/api/v1/received-documents', ['vat_recap' => ['21;1000']] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'vat_recap', 'reason' => 'vat_recap_line_invalid']);

        $this->send('POST', '/api/v1/received-documents', ['supplier_id' => '01940000-0000-7000-8000-000000000001'] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'supplier_id', 'reason' => 'supplier_unknown']);

        $this->send('POST', '/api/v1/received-documents', ['total' => '1210.001'] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'total', 'reason' => 'amount_invalid']);

        $this->send('POST', '/api/v1/received-documents', ['vat_recap' => ['21:1000.00:210.00:1:1']] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'vat_recap', 'reason' => 'vat_recap_line_invalid']);

        $this->send('POST', '/api/v1/received-documents', ['currency' => 'XYZ'] + $this->fields());
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'currency', 'reason' => 'currency_unsupported']);

        $this->send('POST', '/api/v1/received-documents', ['firm_id' => '01940000-0000-7000-8000-000000000001'] + $this->fields());
        $this->assertProblem(404, 'firm_unknown');

        $this->send('POST', '/api/v1/received-documents/01940000-0000-7000-8000-000000000001/payments', ['paid_on' => '2026-11-12', 'amount' => '1.00']);
        $this->assertProblem(404, 'not_found');
    }

    public function testADocumentInAnotherCurrencyKeepsTheVatInCzkItPrints(): void
    {
        $this->send('POST', '/api/v1/received-documents', ['currency' => 'EUR', 'exchange_rate' => '25', 'total' => '121.00', 'vat_recap' => ['21:100.00:21.00:530.00']] + $this->fields());

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['amount_minor' => 10_000, 'currency' => 'EUR'], $this->at('data', 'vat_recap', 0, 'base'));
        self::assertSame(['amount_minor' => 53_000, 'currency' => 'CZK'], $this->at('data', 'vat_recap', 0, 'vat_czk'));
    }

    public function testTheSchemaTellsTheLengthsOfTheShortFields(): void
    {
        $properties = InputSchema::of(self::getContainer()->get(Operations::class)->get('received_documents.record')->fields)['properties'];
        self::assertIsArray($properties);

        self::assertSame([10, 3], [$properties['variable_symbol']['maxLength'] ?? null, $properties['currency']['maxLength'] ?? null]);
    }

    public function testPaymentsAndMarkAsPaidOverRestAndTheUnpaidList(): void
    {
        $open = $this->recorded('FV-1');
        $paid = $this->recorded('FV-2');

        $this->send('POST', '/api/v1/received-documents/'.$paid.'/payments', ['paid_on' => '2026-11-12', 'amount' => '210.00', 'method' => 'card']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['amount_minor' => 100_000, 'currency' => 'CZK'], $this->at('data', 'remaining'));
        $this->send('POST', '/api/v1/received-documents/'.$paid.'/payments', ['paid_on' => '2026-11-12', 'amount' => '1000.01']);
        $this->assertProblem(409, 'overpaid');

        $this->send('POST', '/api/v1/received-documents/'.$paid.'/mark-paid', ['method' => 'bank_transfer']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'is_paid'));
        $payments = $this->at('data', 'payments');
        self::assertIsArray($payments);
        self::assertCount(2, $payments);
        $this->send('POST', '/api/v1/received-documents/'.$paid.'/mark-paid', []);
        $this->assertProblem(409, 'paid');

        $this->get('/api/v1/received-documents', ['unpaid' => 'true', 'due_before' => '2026-12-01', 'firm_id' => $this->firmId], $this->writer);
        self::assertSame([$open], $this->ids());
        $this->get('/api/v1/received-documents/'.$paid.'/payments', secret: $this->writer);
        $data = $this->at('data');
        self::assertIsArray($data);
        self::assertSame(['card', 'bank_transfer'], array_column($data, 'method'));
    }

    public function testTrashOverMcpAnswersWhatRestReads(): void
    {
        $id = $this->recorded('FV-3');

        $call = $this->tool('foresi_trash_received_document', ['document_id' => $id, 'reason' => 'Recorded twice.']);
        self::assertFalse($call['isError'] ?? false);
        $this->get('/api/v1/received-documents', ['trashed' => 'true'], $this->writer);
        self::assertSame([$id], $this->ids());

        $call = $this->tool('foresi_mark_received_document_paid', ['document_id' => $id]);
        self::assertTrue($call['isError'] ?? false);
    }

    public function testAReadTokenReadsButDoesNotWrite(): void
    {
        $id = $this->recorded('FV-4');
        $reader = $this->issued([ApiScope::from('received_documents:read')]);

        $this->get('/api/v1/received-documents/'.$id, secret: $reader);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/received-documents/'.$id.'/mark-paid', [], $reader);
        self::assertResponseStatusCodeSame(403);
        $this->get('/api/v1/received-documents/'.$id, secret: $this->secret);
        self::assertResponseStatusCodeSame(200);
        $contacts = $this->issued([ApiScope::from('contacts:read')]);
        $this->get('/api/v1/received-documents', secret: $contacts);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(string $number = 'FV-2026-123'): array
    {
        return [
            'firm_id' => $this->firmId,
            'kind' => 'tax_invoice',
            'supplier_id' => $this->customerId,
            'supplier_number' => $number,
            'issue_date' => '2026-11-02',
            'due_date' => '2026-11-16',
            'received_date' => '2026-11-03',
            'total' => '1210.00',
            'vat_recap' => ['21:1000.00:210.00'],
        ];
    }

    private function recorded(string $number): string
    {
        $this->send('POST', '/api/v1/received-documents', $this->fields($number));
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
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
