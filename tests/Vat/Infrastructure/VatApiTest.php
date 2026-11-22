<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Infrastructure;

use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The VAT periods and payment details over REST and MCP: a member with the
 * vat scopes reads and writes them, a token without the scope is refused,
 * and a guest never reaches them.
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
        self::assertSame(['schedules_not_counted', 'plans_not_counted', 'deduction_before_registration'], $data['known_gaps']);
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
            'breakdown' => [
                'documents' => ['output' => ['amount_minor' => 0, 'currency' => 'CZK'], 'input' => ['amount_minor' => 0, 'currency' => 'CZK']],
                'schedules' => ['output' => ['amount_minor' => 0, 'currency' => 'CZK'], 'input' => ['amount_minor' => 0, 'currency' => 'CZK']],
                'plans' => ['output' => ['amount_minor' => 0, 'currency' => 'CZK'], 'input' => ['amount_minor' => 0, 'currency' => 'CZK']],
            ],
            'steps' => [
                ['name' => 'output', 'amount' => ['amount_minor' => 0, 'currency' => 'CZK'], 'rule' => 'output VAT of the documents in the period', 'sources' => []],
                ['name' => 'input', 'amount' => ['amount_minor' => 0, 'currency' => 'CZK'], 'rule' => 'deductible input VAT of the documents in the period', 'sources' => []],
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
        self::assertSame(['invoicing.issued_vat', 'purchasing.received_vat'], $data['restricted_sources'], 'A token with vat:write alone reads no invoice nor received document.');
        self::assertSame([], $data['unavailable_sources']);
        self::assertSame(['schedules_not_counted', 'plans_not_counted', 'deduction_before_registration'], $data['known_gaps']);

        $this->get('/api/v1/vat/periods/2026-10', secret: $this->writer);
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/vat/periods/November', secret: $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'key', 'reason' => 'key_invalid']);

        $reader = $this->issued([ApiScope::from('vat:read'), ApiScope::from('invoices:read'), ApiScope::from('received_documents:read')]);
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

    public function testARangeBeyond24MonthsOrAnUnreadableMonthIsRefused(): void
    {
        $this->get('/api/v1/vat/periods', ['to' => '2028-12'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'to', 'reason' => 'range_too_long']);
        $this->get('/api/v1/vat/periods', ['from' => 'November'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'from', 'reason' => 'from_invalid']);

        $call = $this->tool($this->writer, 'foresi_vat_periods', ['to' => '2028-12']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame(['parameter' => 'to', 'reason' => 'range_too_long'], $this->in($call, 'structuredContent', 'error', 'details'));
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

    public function testATokenWithoutTheScopeIsRefused(): void
    {
        $this->get('/api/v1/vat/periods', secret: $this->issued([ApiScope::from('cashflow:read')]));
        $this->assertProblem(403, 'insufficient_scope');
        $this->send('PUT', '/api/v1/vat/settings', ['office_account' => '705-1/0710'], $this->issued([ApiScope::from('vat:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestIsRefused(): void
    {
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        // An agent of a guest that asked for everything holds only what a guest may hold: nothing of VAT.
        $secret = $this->flushed(function () use ($guest): string {
            $now = self::getContainer()->get(ClockInterface::class)->now();
            $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
            self::getContainer()->get(OAuthStore::class)->addClient($client);
            $secret = ApiTokenSecret::generateOAuth();
            self::getContainer()->get(ApiTokens::class)->add(new ApiToken($guest->id(), 'Example Agent', [ApiScope::write()], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $client->id()));

            return $secret;
        });

        // Over REST a guest's token is never accepted (ADR 0041).
        $this->get('/api/v1/vat/periods', secret: $secret);
        self::assertResponseStatusCodeSame(401);

        // Over MCP the connection works, but no VAT tool is listed or answers.
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $tools = array_column((array) $this->in($this->mcp($secret, 'tools/list'), 'result', 'tools'), 'name');
        self::assertSame([], array_values(array_filter($tools, static fn (mixed $name): bool => \is_string($name) && str_contains($name, 'vat') && !str_contains($name, 'firm_vat'))));
        $call = $this->in($this->mcp($secret, 'tools/call', ['name' => 'foresi_vat_periods', 'arguments' => new \stdClass()]), 'result');
        self::assertIsArray($call);
        self::assertTrue($call['isError'] ?? false);
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
