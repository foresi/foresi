<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\Country;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The VAT periods and payment details of a Czech firm over REST and MCP: a
 * member with the vat scopes reads and writes them.
 */
final class VatApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('vat:write')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAMemberReadsThePeriodsOnBothSurfaces(): void
    {
        $this->flushed(function (): void {
            $this->em()->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
            $this->em()->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12')));
        });

        $this->get('/api/v1/vat/periods', ['from' => '2026-11', 'to' => '2027-01'], $this->writer);
        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertIsArray($data);
        self::assertSame([true, null, 'CZ', '2026-11', '2027-01'], [$data['estimate'], $data['reason'], $data['country'], $data['from'], $data['to']]);
        self::assertSame(['deduction_before_registration'], $data['known_gaps']);
        self::assertIsArray($data['unverified']);
        self::assertContains('refund_timing', $data['unverified']);
        self::assertSame([
            'key' => '2026-11',
            'length' => 'month',
            'start' => '2026-11-12',
            'end' => '2026-11-30',
            'due_on' => '2026-12-28',
            'refund_expected_on' => '2027-01-27',
            'output' => ['amount_minor' => 0, 'currency' => 'CZK'],
            'input' => ['amount_minor' => 0, 'currency' => 'CZK'],
            'result' => ['amount_minor' => 0, 'currency' => 'CZK'],
            'return_amount' => null,
            'expected' => ['amount_minor' => 0, 'currency' => 'CZK'],
            'state' => 'estimated',
            'paid' => null,
            'difference' => null,
            'payments' => [],
            'settled_mark' => null,
            'breakdown' => [
                'documents' => ['output' => ['amount_minor' => 0, 'currency' => 'CZK'], 'input' => ['amount_minor' => 0, 'currency' => 'CZK']],
                'schedules' => ['output' => ['amount_minor' => 0, 'currency' => 'CZK'], 'input' => ['amount_minor' => 0, 'currency' => 'CZK']],
                'plans' => ['output' => ['amount_minor' => 0, 'currency' => 'CZK'], 'input' => ['amount_minor' => 0, 'currency' => 'CZK']],
            ],
            'steps' => [
                ['name' => 'output', 'amount' => ['amount_minor' => 0, 'currency' => 'CZK'], 'rule' => 'output VAT of the documents, the schedules and the plans in the period', 'sources' => []],
                ['name' => 'input', 'amount' => ['amount_minor' => 0, 'currency' => 'CZK'], 'rule' => 'deductible input VAT of the documents and the plans in the period', 'sources' => []],
                ['name' => 'result', 'amount' => ['amount_minor' => 0, 'currency' => 'CZK'], 'rule' => 'output VAT minus deductible input VAT', 'sources' => ['output', 'input']],
            ],
            'warnings' => [],
            'missing_czk' => [],
        ], $this->at('data', 'periods', 0));
        self::assertSame([], $data['unavailable_sources']);
        self::assertSame(['2026-11', '2026-12', '2027-01'], array_column((array) $data['periods'], 'key'));
        self::assertStringNotContainsString('overdue', (string) $this->client->getResponse()->getContent());

        $call = $this->tool($this->writer, 'foresi_vat_periods', ['from' => '2026-11', 'to' => '2027-01']);
        self::assertFalse($call['isError'] ?? false);
        $structured = $this->in($call, 'structuredContent', 'data');
        self::assertIsArray($structured);
        self::assertSame($data['periods'], $structured['periods']);
        self::assertSame(['office_account' => null, 'variable_symbol' => null], array_slice((array) $structured['settings'], 0, 2));
    }

    public function testOnePeriodWithItsDocumentsOnBothSurfaces(): void
    {
        $this->flushed(function (): void {
            $this->em()->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12')));
        });

        $this->get('/api/v1/vat/periods/2026-11', secret: $this->writer);
        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertIsArray($data);
        self::assertSame([true, '2026-11', '2026-11-12', []], [$data['estimate'], $this->at('data', 'period', 'key'), $this->at('data', 'period', 'start'), $data['documents']]);
        self::assertSame(['invoicing.issued_vat', 'received-documents.received_vat', 'invoicing.recurring_vat', 'cash-flow.planned_vat'], $data['restricted_sources'], 'A token with vat:write alone reads no invoice, received document nor planned item.');
        self::assertSame([[], []], [$data['schedules'], $data['plans']]);
        self::assertSame([], $data['unavailable_sources']);
        self::assertSame(['deduction_before_registration'], $data['known_gaps']);

        $this->get('/api/v1/vat/periods/2026-10', secret: $this->writer);
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/vat/periods/November', secret: $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'key', 'reason' => 'key_invalid']);

        $reader = $this->issued([ApiScope::from('vat:read'), ApiScope::from('invoices:read'), ApiScope::from('received_documents:read'), ApiScope::from('cashflow:read')]);
        $call = $this->tool($reader, 'foresi_get_vat_period', ['key' => '2026-11']);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame(['2026-11', []], [$this->in($call, 'structuredContent', 'data', 'period', 'key'), $this->in($call, 'structuredContent', 'data', 'restricted_sources')]);
        $missing = $this->tool($reader, 'foresi_get_vat_period', ['key' => '2026-10']);
        self::assertTrue($missing['isError'] ?? false);
        self::assertSame('not_found', $this->in($missing, 'structuredContent', 'error', 'code'));
    }

    public function testThePayerDateMissingIsAnAnswerNotAnError(): void
    {
        $this->get('/api/v1/vat/periods', secret: $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame(['payer_date_missing', []], [$this->at('data', 'reason'), $this->at('data', 'periods')]);
    }

    public function testAMemberSetsThePaymentDetailsAndTheSymbolDefaultsToTheVatId(): void
    {
        $this->get('/api/v1/vat/settings', secret: $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame(['office_account' => null, 'variable_symbol' => null, 'suggestion' => ['account_hint' => '705-<number of your tax office>/0710', 'variable_symbol' => '12345695', 'source_url' => 'https://financnisprava.gov.cz/cs/dane/dane/dan-z-pridane-hodnoty']], $this->json()['data']);

        $this->send('PUT', '/api/v1/vat/settings', ['office_account' => ' 705-12345678/0710 '], $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame(['705-12345678/0710', '12345695'], [$this->at('data', 'office_account'), $this->at('data', 'variable_symbol')]);

        $call = $this->tool($this->writer, 'foresi_set_vat_settings', ['office_account' => '705-87654321/0710', 'variable_symbol' => '99']);
        self::assertFalse($call['isError'] ?? false);

        $this->get('/api/v1/vat/settings', secret: $this->writer);
        self::assertSame(['705-87654321/0710', '99'], [$this->at('data', 'office_account'), $this->at('data', 'variable_symbol')]);

        $this->send('PUT', '/api/v1/vat/settings', ['office_account' => '  '], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'office_account', 'reason' => 'office_account_invalid']);
        $this->send('PUT', '/api/v1/vat/settings', ['office_account' => '705-1/0710', 'variable_symbol' => str_repeat('9', 36)], $this->writer);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAMemberEntersTheReturnSettlesAndPlacesAPaymentOnBothSurfaces(): void
    {
        $this->flushed(function (): void {
            $this->em()->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12')));
        });

        $this->send('PUT', '/api/v1/vat/periods/2026-11/return-amount', ['amount' => '1890.00'], $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame([['amount_minor' => 189_000, 'currency' => 'CZK'], 'return_entered'], [$this->at('data', 'period', 'return_amount'), $this->at('data', 'period', 'state')]);
        $this->send('PUT', '/api/v1/vat/periods/2026-11/return-amount', ['amount' => 'a lot'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'amount', 'reason' => 'amount_invalid']);

        $this->send('POST', '/api/v1/vat/periods/2026-11/settle', ['note' => 'Paid from savings.'], $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame(['settled', 'Paid from savings.'], [$this->at('data', 'period', 'state'), $this->at('data', 'period', 'settled_mark', 'note')]);
        $this->send('DELETE', '/api/v1/vat/periods/2026-11/settle', [], $this->writer);
        self::assertSame(['return_entered', null], [$this->at('data', 'period', 'state'), $this->at('data', 'period', 'settled_mark')]);
        $this->send('DELETE', '/api/v1/vat/periods/2026-11/return-amount', [], $this->writer);
        self::assertSame([null, 'estimated'], [$this->at('data', 'period', 'return_amount'), $this->at('data', 'period', 'state')]);
        $this->send('DELETE', '/api/v1/vat/periods/2026-11/return-amount', [], $this->writer);
        $this->assertProblem(404, 'not_found');
        $this->send('POST', '/api/v1/vat/periods/2026-10/settle', [], $this->writer);
        $this->assertProblem(404, 'not_found');

        $this->send('PUT', '/api/v1/vat/payments/m1', ['period' => '2026-11'], $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame(['movement_id' => 'm1', 'period' => '2026-11', 'automatic' => false], $this->json()['data']);
        $this->send('PUT', '/api/v1/vat/payments/m1', ['period' => null], $this->writer);
        self::assertSame(['movement_id' => 'm1', 'period' => null, 'automatic' => false], $this->json()['data']);
        $this->send('DELETE', '/api/v1/vat/payments/m1', [], $this->writer);
        self::assertSame(['movement_id' => 'm1', 'period' => null, 'automatic' => true], $this->json()['data']);

        foreach ([
            ['foresi_set_vat_return_amount', ['key' => '2026-11', 'amount' => '-300.00'], ['data', 'period', 'expected', 'amount_minor'], -30_000],
            ['foresi_clear_vat_return_amount', ['key' => '2026-11'], ['data', 'period', 'state'], 'estimated'],
            ['foresi_settle_vat_period', ['key' => '2026-11'], ['data', 'period', 'state'], 'settled'],
            ['foresi_reopen_vat_period', ['key' => '2026-11'], ['data', 'period', 'state'], 'estimated'],
            ['foresi_assign_vat_payment', ['movement_id' => 'm2', 'period' => '2026-11'], ['data', 'period'], '2026-11'],
            ['foresi_reset_vat_payment', ['movement_id' => 'm2'], ['data', 'automatic'], true],
        ] as [$tool, $arguments, $path, $expected]) {
            $call = $this->tool($this->writer, $tool, $arguments);
            self::assertFalse($call['isError'] ?? false, $tool);
            self::assertSame($expected, $this->in($call, 'structuredContent', ...$path), $tool);
        }

        $this->send('POST', '/api/v1/vat/periods/2026-11/settle', [], $this->issued([ApiScope::from('vat:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body, string $secret): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $secret, string $name, array $arguments): array
    {
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $result = $this->in($this->mcp($secret, 'tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]), 'result');
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
