<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The estimate over REST and MCP with Cash flow: the planned items a token of
 * cashflow:write entered count in the year end.
 */
final class TaxesEstimateApiTest extends ApiTestCase
{
    private string $owner;
    private string $ownerId;
    private string $memberWriter;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $owner = $this->fixtures->user('owner@example.com');
        $this->fixtures->join($owner, Role::Owner);
        $this->ownerId = $owner->id();
        $this->owner = $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($owner->id(), 'Taxes', [ApiScope::from('taxes:write')])->secret);
        $this->memberWriter = $this->issued([ApiScope::from('taxes:write')]);
        // The firm of the API fixtures is a VAT payer; this owner was not one until a stretch says so.
        FirmVat::from($this->em(), 'no', '2000-01-01');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheOwnerReadsTheEstimateOnBothSurfaces(): void
    {
        $planner = $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->ownerId, 'Planning', [ApiScope::from('cashflow:write')])->secret);
        $this->send($planner, 'POST', '/api/v1/cash-flow/planned-items', ['direction' => 'in', 'name' => 'Consulting not invoiced yet', 'category' => 'revenue', 'amount' => '10000.00', 'on' => '2026-12-15']);
        self::assertResponseStatusCodeSame(201);
        $this->send($planner, 'POST', '/api/v1/cash-flow/planned-items', ['direction' => 'out', 'name' => 'Social advance', 'category' => 'insurance', 'amount' => '5005.00', 'on' => '2026-12-31']);
        self::assertResponseStatusCodeSame(201);

        $this->get('/api/v1/taxes-and-contributions/estimate?year=2026', secret: $this->owner);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'estimate'));
        self::assertSame('cz.sole_trader', $this->at('data', 'to_date', 'regime'));
        self::assertSame([2026, 'published'], [$this->at('data', 'to_date', 'rules', 'year'), $this->at('data', 'to_date', 'rules', 'status')]);
        // The year end apart from to date: the planned income as stated, the insurance advance left out, every month counted.
        self::assertNull($this->at('data', 'year_end_reason'));
        self::assertSame('cz.sole_trader', $this->at('data', 'year_end', 'regime'));
        self::assertSame(['amount_minor' => 0, 'currency' => 'CZK'], $this->at('data', 'to_date', 'methods', 0, 'totals', 'income'));
        self::assertSame(['amount_minor' => 1_000_000, 'currency' => 'CZK'], $this->at('data', 'year_end', 'methods', 0, 'totals', 'income'));
        self::assertSame(['amount_minor' => 0, 'currency' => 'CZK'], $this->at('data', 'year_end', 'methods', 0, 'totals', 'costs'));
        self::assertCount(12, (array) $this->at('data', 'year_end', 'months'));
        // A token with the taxes scopes alone may not read the invoices and received documents themselves.
        self::assertSame(['2026-11-20', '2026-12-31', ['forecast.plan_no_vat_split'], false, []], [$this->at('data', 'forecast', 'from'), $this->at('data', 'forecast', 'to'), $this->at('data', 'forecast', 'flags'), $this->at('data', 'forecast', 'incomplete'), $this->at('data', 'forecast', 'unavailable_sources')]);
        self::assertContains('invoicing.invoices', (array) $this->at('data', 'forecast', 'restricted_sources'));
        self::assertSame(['amount_minor' => 1_000_000, 'currency' => 'CZK'], $this->at('data', 'forecast', 'totals', 'income', 'counted'));
        self::assertSame(['document', 'schedule', 'plan'], array_keys((array) $this->at('data', 'forecast_by_certainty')));
        self::assertSame(['amount_minor' => 1_000_000, 'currency' => 'CZK'], $this->at('data', 'forecast_by_certainty', 'plan', 'income', 'gross'));
        self::assertSame(['amount_minor' => 0, 'currency' => 'CZK'], $this->at('data', 'forecast_by_certainty', 'document', 'income', 'counted'));
        self::assertSame(['actual', 'lump_60'], [$this->at('data', 'accruals', 0, 'method'), $this->at('data', 'accruals', 1, 'method')]);
        self::assertSame('2026-01', $this->at('data', 'accruals', 0, 'months', 0, 'month'));
        self::assertSame(['profit', 'minimum', 'levies'], array_slice(array_keys((array) $this->at('data', 'accruals', 0, 'months', 11)), 1));
        self::assertSame(['actual', 'lump_60'], [$this->at('data', 'to_date', 'methods', 0, 'method'), $this->at('data', 'to_date', 'methods', 1, 'method')]);
        foreach (['profit', 'social', 'health', 'sickness', 'tax_base', 'income_tax', 'credits', 'child_benefit'] as $index => $key) {
            self::assertSame($key, $this->at('data', 'to_date', 'methods', 0, 'steps', $index, 'key'));
        }
        // No income yet, main activity assumed January to November: eleven months of the 35 % minimum.
        self::assertSame(['amount_minor' => 17_139 * 11 * 100, 'currency' => 'CZK'], $this->at('data', 'to_date', 'methods', 0, 'totals', 'social_base'));
        $estimate = $this->json()['data'];

        $this->get('/api/v1/taxes-and-contributions/estimate', secret: $this->owner);
        self::assertSame(2026, $this->at('data', 'year'), 'Without a year, this one.');

        $call = $this->tool($this->owner, 'foresi_tax_estimate', ['year' => 2026]);
        self::assertFalse($call['isError'] ?? false);
        self::assertEquals($estimate, $this->in($call, 'structuredContent', 'data'));

        self::assertStringContainsString('Year end with the forecast: actual: levies', $this->text($call));

        $this->get('/api/v1/taxes-and-contributions/estimate?year=2027', secret: $this->owner);
        self::assertSame('year_not_started', $this->at('data', 'to_date_reason'));
        self::assertSame([null, 'year_not_started', null, null, []], [$this->at('data', 'year_end'), $this->at('data', 'year_end_reason'), $this->at('data', 'forecast'), $this->at('data', 'forecast_by_certainty'), $this->at('data', 'accruals')]);
        $next = $this->tool($this->owner, 'foresi_tax_estimate', ['year' => 2027]);
        self::assertStringContainsString('No estimate to date for 2027: year_not_started. No year end: year_not_started.', $this->text($next));
        foreach (['2028', '1999', 'last'] as $year) {
            $this->get('/api/v1/taxes-and-contributions/estimate?year='.$year, secret: $this->owner);
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'year', 'reason' => 'invalid_year']);
        }
        $this->get('/api/v1/taxes-and-contributions/estimate', secret: $this->memberWriter);
        $this->assertProblem(403, 'owner_only');
        $read = $this->tool($this->memberWriter, 'foresi_tax_estimate', []);
        self::assertSame('owner_only', $this->in($read, 'structuredContent', 'error', 'code'));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $secret, string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
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

    /**
     * @param array<mixed> $call
     */
    private function text(array $call): string
    {
        $text = $this->in($call, 'content', 0, 'text');
        self::assertIsString($text);

        return $text;
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
