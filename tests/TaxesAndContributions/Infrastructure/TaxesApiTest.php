<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Infrastructure;

use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The owner profile over REST and MCP: the writes declared once with
 * #[Operation], the read under taxes:read, both for the owner only (B7).
 */
final class TaxesApiTest extends ApiTestCase
{
    private const array YEAR = [
        'expense_methods' => ['actual', 'lump_60'],
        'chosen_method' => 'lump_60',
        'children' => [['label' => 'Elder daughter', 'birth_date' => '2019-05-14', 'order' => 1, 'ztp_p' => false, 'months' => 12]],
        'spouse_months' => 6,
        'deductions' => [['type' => 'pension_products', 'amount' => '24000.00']],
        'employment_base' => '120000.00',
        'previous_social_base' => '210000.00',
        'return_route' => 'advisor',
    ];

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
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheOwnerBuildsTheProfileAndReadsItBackOnBothSurfaces(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'vat_payer', 'value' => 'yes', 'valid_from' => '2026-11-12']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['vat_payer', 'yes', '2026-11-12', null], [$this->at('data', 'kind'), $this->at('data', 'value'), $this->at('data', 'valid_from'), $this->at('data', 'base')]);
        $statusId = $this->at('data', 'id');
        self::assertIsString($statusId);

        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'sickness_insurance', 'value' => 'on', 'valid_from' => '2026-01-01', 'base' => '10000.00']);
        self::assertSame(['amount_minor' => 1000000, 'currency' => 'CZK'], $this->at('data', 'base'));

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', self::YEAR);
        self::assertResponseIsSuccessful();
        self::assertSame(2026, $this->at('data', 'year'));
        self::assertSame(['months' => 6, 'ztp_p' => false, 'low_income' => false], $this->at('data', 'spouse'));
        self::assertSame([['type' => 'pension_products', 'amount' => ['amount_minor' => 2400000, 'currency' => 'CZK']]], $this->at('data', 'deductions'));
        self::assertSame(['amount_minor' => 12000000, 'currency' => 'CZK'], $this->at('data', 'other_bases', 'employment'));
        $year = $this->json()['data'];

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/authorities/health', ['account' => '1234567890/0300', 'variable_symbol' => '8501011234']);
        self::assertSame(['authority' => 'health', 'account' => '1234567890/0300', 'variable_symbol' => '8501011234'], $this->json()['data']);

        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->owner);
        self::assertResponseIsSuccessful();
        $profile = $this->json()['data'];
        self::assertSame(['vat_payer', 'sickness_insurance'], [$this->at('data', 'statuses', 0, 'kind'), $this->at('data', 'statuses', 1, 'kind')]);
        self::assertSame([$year], $this->at('data', 'years'));

        $call = $this->tool($this->owner, 'foresi_get_tax_profile', []);
        self::assertFalse($call['isError'] ?? false);
        self::assertEquals($profile, $this->in($call, 'structuredContent', 'data'));

        $this->send($this->owner, 'DELETE', '/api/v1/taxes-and-contributions/profile/statuses/'.$statusId);
        self::assertSame(['id' => $statusId, 'removed' => true], $this->json()['data']);
        $this->send($this->owner, 'DELETE', '/api/v1/taxes-and-contributions/profile/statuses/'.$statusId);
        $this->assertProblem(404, 'not_found');
    }

    public function testAStatusOnATakenDayIsAConflictAndABadValueNamesItsParameter(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'vat_payer', 'value' => 'no', 'valid_from' => '2026-01-01']);
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'vat_payer', 'value' => 'yes', 'valid_from' => '2026-01-01']);
        $this->assertProblem(409, 'status_day_taken');

        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'sickness_insurance', 'value' => 'on', 'valid_from' => '2026-01-01']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'base', 'reason' => 'base_required']);

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', ['children' => [['label' => 'Elder daughter', 'birth_date' => '2019-05-14', 'order' => 1, 'months' => 13]]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'children', 'reason' => 'months_invalid']);
    }

    public function testTheOwnerReadsTheAmountsOfAYearAndABadYearNamesItsParameter(): void
    {
        $this->get('/api/v1/taxes-and-contributions/amounts?year=2025', secret: $this->owner);
        self::assertResponseIsSuccessful();
        self::assertSame(['2025-01-01', '2025-12-31'], [$this->at('data', 'from'), $this->at('data', 'to')]);
        self::assertSame(['gross' => ['amount_minor' => 0, 'currency' => 'CZK'], 'net' => ['amount_minor' => 0, 'currency' => 'CZK'], 'vat' => ['amount_minor' => 0, 'currency' => 'CZK'], 'counted' => ['amount_minor' => 0, 'currency' => 'CZK']], $this->at('data', 'totals', 'income'));
        self::assertSame(['non_deductible_costs', 'assets'], $this->at('data', 'known_gaps'));
        $amounts = $this->json()['data'];

        $this->get('/api/v1/taxes-and-contributions/amounts', secret: $this->owner);
        self::assertSame('2026-01-01', $this->at('data', 'from'), 'Without a year, this one.');

        $call = $this->tool($this->owner, 'foresi_tax_amounts', ['year' => 2025]);
        self::assertFalse($call['isError'] ?? false);
        self::assertEquals($amounts, $this->in($call, 'structuredContent', 'data'));

        foreach (['2027', '1999', 'last'] as $year) {
            $this->get('/api/v1/taxes-and-contributions/amounts?year='.$year, secret: $this->owner);
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'year', 'reason' => 'invalid_year']);
        }
        $this->get('/api/v1/taxes-and-contributions/amounts?year=2026', secret: $this->memberWriter);
        $this->assertProblem(403, 'owner_only');
        $read = $this->tool($this->memberWriter, 'foresi_tax_amounts', []);
        self::assertSame('owner_only', $this->in($read, 'structuredContent', 'error', 'code'));
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

    public function testTheEstimateListsThePaymentsWithTheirStateAndMatchRuleOnBothSurfaces(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-01-01']);
        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/authorities/social', ['account' => '123-456789/0100', 'variable_symbol' => '7805121234']);
        self::assertResponseIsSuccessful();

        $this->get('/api/v1/taxes-and-contributions/estimate?year=2026', secret: $this->owner);
        self::assertResponseIsSuccessful();
        $payments = (array) $this->at('data', 'payments');
        $byKey = array_column($payments, null, 'key');
        self::assertSame([
            'key' => 'social.advance.2026-12',
            'authority' => 'social',
            'kind' => 'advance',
            'period' => '2026-12',
            'direction' => 'out',
            'payable_from' => '2026-12-01',
            'due_on' => '2026-12-31',
            'amount' => ['amount_minor' => 500_500, 'currency' => 'CZK'],
            'state' => 'expected',
            'match_rule' => ['account' => '123-456789/0100', 'variable_symbol' => '7805121234'],
            'paid_by' => null,
        ], $byKey['social.advance.2026-12']);
        self::assertSame(['overdue', null], [$this->at('data', 'payments', $this->index($payments, 'health.advance.2026-09'), 'state'), $this->at('data', 'payments', $this->index($payments, 'health.advance.2026-09'), 'match_rule')]);
        self::assertSame('in', $this->at('data', 'payments', $this->index($payments, 'social.overpayment.2026'), 'direction'));
        self::assertSame([], $this->at('data', 'possible_duplicates'));
        self::assertContains('no_match_rule', (array) $this->at('data', 'warnings'));

        $call = $this->tool($this->owner, 'foresi_tax_estimate', ['year' => 2026]);
        self::assertEquals($payments, $this->in($call, 'structuredContent', 'data', 'payments'));

        $this->get('/api/v1/taxes-and-contributions/estimate?year=2027', secret: $this->owner);
        self::assertSame(['social.advance.2027-01', 'expected'], [$this->at('data', 'payments', 0, 'key'), $this->at('data', 'payments', 0, 'state')], 'Next year has its advances before it starts.');
    }

    public function testTheEstimateHasTheFlatRateApartOnBothSurfaces(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-01-01']);
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'flat_rate_band', 'value' => '1', 'valid_from' => '2026-01-01']);
        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/authorities/flat_rate', ['account' => '7720-77628031/0710', 'variable_symbol' => '7805121234']);
        self::assertResponseIsSuccessful();

        $this->get('/api/v1/taxes-and-contributions/estimate?year=2026', secret: $this->owner);
        self::assertResponseIsSuccessful();
        // January to November: six months of 9,984 and five of 9,162.
        self::assertSame(['amount_minor' => (6 * 9_984 + 5 * 9_162) * 100, 'currency' => 'CZK'], $this->at('data', 'to_date', 'flat_rate', 'total'));
        self::assertSame(['month' => '2026-07', 'band' => '1', 'income_tax' => ['amount_minor' => 10_000, 'currency' => 'CZK'], 'social' => ['amount_minor' => 575_600, 'currency' => 'CZK'], 'health' => ['amount_minor' => 330_600, 'currency' => 'CZK'], 'total' => ['amount_minor' => 916_200, 'currency' => 'CZK']], $this->at('data', 'to_date', 'flat_rate', 'months', 6));
        self::assertSame($this->at('data', 'to_date', 'flat_rate', 'total'), $this->at('data', 'to_date', 'methods', 0, 'totals', 'flat_rate'));
        self::assertSame(['amount_minor' => 0, 'currency' => 'CZK'], $this->at('data', 'to_date', 'methods', 0, 'totals', 'social'));
        self::assertSame(['flat_rate', '1'], [$this->at('data', 'to_date', 'methods', 0, 'steps', 8, 'key'), $this->at('data', 'to_date', 'months', 0, 'flat_rate_band')]);
        $payments = (array) $this->at('data', 'payments');
        $december = $this->index($payments, 'flat_rate.advance.2026-12');
        self::assertSame(['flat_rate', '2026-12-21', ['account' => '7720-77628031/0710', 'variable_symbol' => '7805121234']], [$this->at('data', 'payments', $december, 'authority'), $this->at('data', 'payments', $december, 'due_on'), $this->at('data', 'payments', $december, 'match_rule')]);
        self::assertNotContains('social.advance.2026-12', array_column($payments, 'key'));
        $estimate = $this->json()['data'];

        $call = $this->tool($this->owner, 'foresi_tax_estimate', ['year' => 2026]);
        self::assertEquals($estimate, $this->in($call, 'structuredContent', 'data'));
        self::assertStringContainsString('Flat-rate tax of 11 months: ', $this->text($call));
    }

    public function testAMemberIsRefusedWithOwnerOnly(): void
    {
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->memberWriter);
        $this->assertProblem(403, 'owner_only');
        // A token that reads everything still reads no profile of a member.
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->secret);
        $this->assertProblem(403, 'owner_only');

        $this->send($this->memberWriter, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'vat_payer', 'value' => 'yes', 'valid_from' => '2026-11-12']);
        $this->assertProblem(403, 'owner_only');
        $this->send($this->memberWriter, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', self::YEAR);
        $this->assertProblem(403, 'owner_only');
        $this->send($this->memberWriter, 'PUT', '/api/v1/taxes-and-contributions/profile/authorities/health', ['account' => '1/0300']);
        $this->assertProblem(403, 'owner_only');

        $read = $this->tool($this->memberWriter, 'foresi_get_tax_profile', []);
        self::assertTrue($read['isError'] ?? false);
        self::assertSame('owner_only', $this->in($read, 'structuredContent', 'error', 'code'));
        $write = $this->tool($this->memberWriter, 'foresi_add_tax_status', ['kind' => 'vat_payer', 'value' => 'yes', 'valid_from' => '2026-11-12']);
        self::assertTrue($write['isError'] ?? false);
        self::assertSame('owner_only', $this->in($write, 'structuredContent', 'error', 'code'));

        // The token without the scope is refused for the scope first.
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->issued([ApiScope::from('cashflow:read')]));
        $this->assertProblem(403, 'insufficient_scope');
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

    /**
     * @param array<mixed> $payments
     */
    private function index(array $payments, string $key): int
    {
        $index = array_search($key, array_column($payments, 'key'), true);

        return \is_int($index) ? $index : self::fail('No payment '.$key);
    }
}
