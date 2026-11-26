<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The cash flow over REST and MCP: the writes declared once with #[Operation],
 * the reads under cashflow:read, and the forecast the same on both surfaces.
 */
final class CashFlowApiTest extends ApiTestCase
{
    private const array RENT = [
        'direction' => 'out',
        'name' => 'Office rent',
        'category' => 'rent',
        'amount' => '10000.00',
        'on' => '2026-11-25',
        'repeat' => 'month',
        'ends_after' => 12,
    ];

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-17 10:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('cashflow:write')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testCreatesAPlannedItemAnswering201WithTheItemAndItsLocation(): void
    {
        $this->send('POST', '/api/v1/cash-flow/planned-items', self::RENT);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/cash-flow/planned-items/'.$id);
        $created = $this->json()['data'];
        self::assertSame(['amount_minor' => 1000000, 'currency' => 'CZK'], $this->at('data', 'amount'));
        self::assertSame(['month', 1, 12, '2026-11-25'], [$this->at('data', 'repeat'), $this->at('data', 'every'), $this->at('data', 'ends_after'), $this->at('data', 'on')]);

        $this->get('/api/v1/cash-flow/planned-items/'.$id, secret: $this->writer);
        self::assertSame($created, $this->json()['data']);
        $this->get('/api/v1/cash-flow/planned-items', [], $this->writer);
        self::assertSame([$created], $this->json()['data']);
    }

    public function testChangesSkipsEndsAndDeletesAPlannedItem(): void
    {
        $id = $this->createdRent();

        $this->send('PATCH', '/api/v1/cash-flow/planned-items/'.$id, ['amount' => '11000.00', 'included' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame([1100000, false], [$this->at('data', 'amount', 'amount_minor'), $this->at('data', 'included')]);

        $this->send('POST', '/api/v1/cash-flow/planned-items/'.$id.'/skip', ['on' => '2026-12-25']);
        self::assertSame(['2026-12-25'], $this->at('data', 'skipped_on'));

        $this->send('POST', '/api/v1/cash-flow/planned-items/'.$id.'/end', ['on' => '2027-02-28']);
        self::assertSame(['2027-02-28', null], [$this->at('data', 'ends_on'), $this->at('data', 'ends_after')]);

        $this->send('DELETE', '/api/v1/cash-flow/planned-items/'.$id);
        self::assertSame(['id' => $id, 'deleted' => true], $this->json()['data']);
        $this->get('/api/v1/cash-flow/planned-items/'.$id, secret: $this->writer);
        $this->assertProblem(404, 'not_found');
    }

    public function testThePlannedItemStatesItsVatOnBothSurfaces(): void
    {
        $this->flushed(function (): void {
            $this->em()->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
            $this->em()->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12')));
        });

        $this->send('POST', '/api/v1/cash-flow/planned-items', ['vat_mode' => 'excluded', 'vat_rate' => '21'] + self::RENT);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['excluded', '21', ['amount_minor' => 1000000, 'currency' => 'CZK'], ['amount_minor' => 210000, 'currency' => 'CZK'], false], [$this->at('data', 'vat_mode'), $this->at('data', 'vat_rate'), $this->at('data', 'net'), $this->at('data', 'vat'), $this->at('data', 'vat_missing')]);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $this->send('PATCH', '/api/v1/cash-flow/planned-items/'.$id, ['vat_mode' => 'included']);
        self::assertSame(['included', '21', 826446, 173554], [$this->at('data', 'vat_mode'), $this->at('data', 'vat_rate'), $this->at('data', 'net', 'amount_minor'), $this->at('data', 'vat', 'amount_minor')], '10,000 with 21 % in it holds 1,735.54 of VAT.');

        $this->send('PATCH', '/api/v1/cash-flow/planned-items/'.$id, ['vat_mode' => 'none']);
        self::assertSame(['none', null, null, null, true], [$this->at('data', 'vat_mode'), $this->at('data', 'vat_rate'), $this->at('data', 'net'), $this->at('data', 'vat'), $this->at('data', 'vat_missing')], 'A payer\'s rent without its VAT misses it.');

        $this->get('/api/v1/cash-flow/planned-items/'.$id, secret: $this->writer);
        self::assertTrue($this->at('data', 'vat_missing'));
        $this->get('/api/v1/cash-flow/planned-items', [], $this->writer);
        self::assertTrue($this->at('data', 0, 'vat_missing'));
        $call = $this->tool('foresi_list_planned_items', []);
        self::assertTrue($this->in($call, 'structuredContent', 'data', 'items', 0, 'vat_missing'));

        $call = $this->tool('foresi_change_planned_item', ['planned_item_id' => $id, 'vat_mode' => 'reverse_charge', 'vat_rate' => '21', 'idempotency_key' => 'reverse-charge-1']);
        self::assertSame(['reverse_charge', '21', 1_000_000, 210_000, false], [
            $this->in($call, 'structuredContent', 'data', 'vat_mode'), $this->in($call, 'structuredContent', 'data', 'vat_rate'),
            $this->in($call, 'structuredContent', 'data', 'net', 'amount_minor'), $this->in($call, 'structuredContent', 'data', 'vat', 'amount_minor'),
            $this->in($call, 'structuredContent', 'data', 'vat_missing'),
        ], 'A reverse charge moves the 10,000 as stated, self-assesses 2,100 and misses no VAT.');
        $this->send('POST', '/api/v1/cash-flow/planned-items', ['vat_mode' => 'reverse_charge', 'vat_rate' => '21', 'direction' => 'in', 'category' => 'revenue'] + self::RENT);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'vat_mode', 'reason' => 'vat_mode_reverse_charge_in']);

        $this->send('POST', '/api/v1/cash-flow/planned-items', ['vat_mode' => 'included'] + self::RENT);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'vat_rate', 'reason' => 'vat_rate_required']);
        $this->send('POST', '/api/v1/cash-flow/planned-items', ['vat_mode' => 'gross', 'vat_rate' => '21'] + self::RENT);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'vat_mode', 'reason' => 'invalid_input']);
    }

    public function testARefusalNamesTheParameterAsTheClientSendsIt(): void
    {
        $this->send('POST', '/api/v1/cash-flow/planned-items', ['ends_on' => '2027-01-01'] + self::RENT);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'ends_after', 'reason' => 'end_ambiguous']);
    }

    public function testRecordsABalanceAndForecastsTheSameOnBothSurfaces(): void
    {
        $this->createdRent();
        $account = $this->flushed(fn (): string => $this->em()->getRepository(FirmBankAccount::class)->findOneBy(['iban' => self::IBAN])?->id() ?? throw new \LogicException('No account.'));

        $this->send('POST', '/api/v1/cash-flow/checkpoints', ['bank_account_id' => $account, 'on' => '2026-11-16', 'amount' => '50000.00']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['2026-11-16', 5000000, self::IBAN], [$this->at('data', 'on'), $this->at('data', 'amount', 'amount_minor'), $this->at('data', 'bank_account', 'iban')]);
        $this->send('POST', '/api/v1/cash-flow/minimum-balance', ['amount' => '45000.00']);
        self::assertSame(['firm_id' => $this->firmId, 'amount' => ['amount_minor' => 4500000, 'currency' => 'CZK']], $this->json()['data']);
        $this->send('POST', '/api/v1/cash-flow/expected-dates', ['subject_type' => 'invoice', 'subject_id' => 'inv-1', 'expected_on' => '2026-12-15']);
        self::assertSame(['subject_type' => 'invoice', 'subject_id' => 'inv-1', 'expected_on' => '2026-12-15'], $this->json()['data']);
        $this->send('DELETE', '/api/v1/cash-flow/expected-dates/invoice/inv-1');
        self::assertSame(['subject_type' => 'invoice', 'subject_id' => 'inv-1', 'expected_on' => null], $this->json()['data']);

        $this->get('/api/v1/cash-flow/forecast', ['months' => '3'], $this->writer);
        self::assertResponseIsSuccessful();
        $forecast = $this->json()['data'];
        self::assertSame(['CZK', '2026-11-17', '2026-11-17', '2027-01-31'], [$this->at('data', 'currency'), $this->at('data', 'today'), $this->at('data', 'from'), $this->at('data', 'to')]);
        self::assertSame([5000000, '2026-11-16', false], [$this->at('data', 'balance', 'amount', 'amount_minor'), $this->at('data', 'balance', 'as_of'), $this->at('data', 'balance', 'stale')]);
        self::assertSame(['2026-W47', 'week', 5000000, 5000000], [$this->at('data', 'periods', 0, 'key'), $this->at('data', 'periods', 0, 'kind'), $this->at('data', 'periods', 0, 'opening', 'amount_minor'), $this->at('data', 'periods', 0, 'closing', 'amount_minor')]);
        self::assertEquals([], $this->at('data', 'periods', 0, 'inflows', 'by_category'));
        $week = $this->at('data', 'periods', 1);
        self::assertIsArray($week);
        self::assertSame(['total' => ['amount_minor' => 1000000, 'currency' => 'CZK'], 'by_category' => ['rent' => ['amount_minor' => 1000000, 'currency' => 'CZK']], 'by_certainty' => ['plan' => ['amount_minor' => 1000000, 'currency' => 'CZK']]], $week['outflows']);
        self::assertEquals([], $this->at('data', 'periods', 1, 'inflows', 'by_certainty'));
        self::assertSame(['document', 'schedule', 'deal', 'plan', 'estimate'], $this->at('data', 'certainty'));
        self::assertSame([
            'source' => 'cash-flow.planned_items',
            'subject' => ['type' => 'planned_item', 'id' => $this->at('data', 'periods', 1, 'items', 0, 'subject', 'id'), 'url' => null],
            'direction' => 'out',
            'label' => 'Office rent',
            'category' => 'rent',
            'certainty' => 'plan',
            'flags' => [],
            'expected_on' => '2026-11-25',
            'counted_on' => '2026-11-25',
            'amount' => ['amount_minor' => 1000000, 'currency' => 'CZK'],
            'amount_czk' => ['amount_minor' => 1000000, 'currency' => 'CZK'],
            'counterparty_id' => null,
        ], $this->at('data', 'periods', 1, 'items', 0));
        self::assertSame(['2027-01-25', ['amount_minor' => 4500000, 'currency' => 'CZK'], '2026-11-25', false, [], []], [$this->at('data', 'lowest', 'on'), $this->at('data', 'minimum_balance'), $this->at('data', 'shortfall_on'), $this->at('data', 'incomplete'), $this->at('data', 'unavailable_sources'), $this->at('data', 'unavailable_rates')]);

        $call = $this->tool('foresi_cash_flow_forecast', ['months' => 3]);
        self::assertFalse($call['isError'] ?? false);
        self::assertEquals($forecast, $this->in($call, 'structuredContent', 'data'));
    }

    public function testACertaintyFilterCountsOnlyItsFlowsOnBothSurfaces(): void
    {
        $this->createdRent();
        $account = $this->flushed(fn (): string => $this->em()->getRepository(FirmBankAccount::class)->findOneBy(['iban' => self::IBAN])?->id() ?? throw new \LogicException('No account.'));
        $this->send('POST', '/api/v1/cash-flow/checkpoints', ['bank_account_id' => $account, 'on' => '2026-11-16', 'amount' => '50000.00']);

        $this->get('/api/v1/cash-flow/forecast', ['months' => '3', 'certainty' => 'estimate,document'], $this->writer);

        self::assertResponseIsSuccessful();
        $forecast = $this->json()['data'];
        self::assertSame(['document', 'estimate'], $this->at('data', 'certainty'));
        // The balance today stays; the plan is not counted, so nothing moves it.
        self::assertSame([5000000, 5000000, 5000000], [$this->at('data', 'balance', 'amount', 'amount_minor'), $this->at('data', 'periods', 1, 'closing', 'amount_minor'), $this->at('data', 'lowest', 'amount', 'amount_minor')]);
        self::assertSame([], $this->at('data', 'periods', 1, 'items'));
        self::assertEquals([], $this->at('data', 'periods', 1, 'outflows', 'by_certainty'));

        $call = $this->tool('foresi_cash_flow_forecast', ['months' => 3, 'certainty' => ['document', 'estimate']]);
        self::assertFalse($call['isError'] ?? false);
        self::assertEquals($forecast, $this->in($call, 'structuredContent', 'data'));

        $this->get('/api/v1/cash-flow/forecast', ['months' => '3', 'certainty' => 'plan'], $this->writer);
        self::assertSame(4000000, $this->at('data', 'periods', 1, 'closing', 'amount_minor'));
    }

    public function testTheForecastRejectsAnUnknownCertaintyOnBothSurfaces(): void
    {
        $this->get('/api/v1/cash-flow/forecast', ['certainty' => 'document,guess'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'certainty', 'reason' => 'invalid_certainty']);

        // The tool's input schema lists the four certainties, so the server refuses another before the tool runs.
        $this->tool('foresi_cash_flow_forecast', []);
        $answer = $this->mcp('tools/call', ['name' => 'foresi_cash_flow_forecast', 'arguments' => ['certainty' => ['guess']]]);
        $result = $answer['result'] ?? null;
        self::assertTrue(isset($answer['error']) || (\is_array($result) && true === ($result['isError'] ?? false)));
    }

    public function testTheForecastRefusesABadParameterOnBothSurfaces(): void
    {
        $this->get('/api/v1/cash-flow/forecast', ['months' => 'many'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'months', 'reason' => 'months_invalid']);

        $call = $this->tool('foresi_cash_flow_forecast', ['from' => '2026-01-01']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame(['code' => 'invalid_argument', 'message' => 'A forecast starts today or later; what happened before is in the balance.', 'details' => ['parameter' => 'from', 'reason' => 'from_in_past']], $this->in($call, 'structuredContent', 'error'));
    }

    public function testTheRestReadsRefuseAFirm(): void
    {
        $firm = '0198a4f0-8b1e-7000-8000-000000000001';
        foreach (['/api/v1/cash-flow/forecast', '/api/v1/cash-flow/planned-items'] as $path) {
            $this->get($path, ['firm_id' => $firm], $this->writer);
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'firm_id']);
        }
    }

    public function testTheMcpToolsRefuseAFirm(): void
    {
        $firm = '0198a4f0-8b1e-7000-8000-000000000001';
        foreach (['foresi_cash_flow_forecast', 'foresi_list_planned_items'] as $name) {
            if (null === $this->session) {
                $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
            }
            // The schema of the tool has no firm_id, and the server refuses what it does not list.
            $answer = $this->mcp('tools/call', ['name' => $name, 'arguments' => ['firm_id' => $firm]]);
            self::assertSame(-32602, $this->in($answer, 'error', 'code'));
            $message = $this->in($answer, 'error', 'message');
            self::assertIsString($message);
            self::assertStringContainsString('firm_id', $message);
        }
    }

    public function testListsThePlannedItemsOverMcp(): void
    {
        $id = $this->createdRent();

        $call = $this->tool('foresi_list_planned_items', []);

        self::assertSame([$id], array_column((array) $this->in($call, 'structuredContent', 'data', 'items'), 'id'));
        $text = $this->in($call, 'content', 0, 'text');
        self::assertIsString($text);
        self::assertStringContainsString('1 planned item.', $text);
    }

    public function testATokenWithoutTheCashFlowScopeReadsNothing(): void
    {
        $this->get('/api/v1/cash-flow/forecast', [], $this->issued([ApiScope::from('contacts:read')]));

        $this->assertProblem(403, 'insufficient_scope');
    }

    private function createdRent(): string
    {
        $this->send('POST', '/api/v1/cash-flow/planned-items', self::RENT);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
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
