<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\Country;

use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Integration\TaxesAndContributions\IssuedPaymentAmounts;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\OpenAmount;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Tests\TaxesAndContributions\Support\DatedAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The estimate of a Czech sole trader over REST and MCP: its payments, its
 * flat rate and its method switch, as the owner reads them.
 */
final class TaxesApiTest extends ApiTestCase
{
    private string $owner;
    private string $ownerId;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $owner = $this->fixtures->user('owner@example.com');
        $this->fixtures->join($owner, Role::Owner);
        $this->ownerId = $owner->id();
        $this->owner = $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($owner->id(), 'Taxes', [ApiScope::from('taxes:write')])->secret);
        // The firm of the API fixtures is a VAT payer; this owner was not one until a stretch says so.
        FirmVat::from($this->em(), 'no', '2000-01-01');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
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
            'amount' => ['amount_minor' => 500_500, 'currency' => 'CZK', 'minor_units' => 2],
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
        self::assertSame(['amount_minor' => (6 * 9_984 + 5 * 9_162) * 100, 'currency' => 'CZK', 'minor_units' => 2], $this->at('data', 'to_date', 'flat_rate', 'total'));
        self::assertSame(['month' => '2026-07', 'band' => '1', 'income_tax' => ['amount_minor' => 10_000, 'currency' => 'CZK', 'minor_units' => 2], 'social' => ['amount_minor' => 575_600, 'currency' => 'CZK', 'minor_units' => 2], 'health' => ['amount_minor' => 330_600, 'currency' => 'CZK', 'minor_units' => 2], 'total' => ['amount_minor' => 916_200, 'currency' => 'CZK', 'minor_units' => 2]], $this->at('data', 'to_date', 'flat_rate', 'months', 6));
        self::assertSame($this->at('data', 'to_date', 'flat_rate', 'total'), $this->at('data', 'to_date', 'methods', 0, 'totals', 'flat_rate'));
        self::assertSame(['amount_minor' => 0, 'currency' => 'CZK', 'minor_units' => 2], $this->at('data', 'to_date', 'methods', 0, 'totals', 'social'));
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

    public function testTheMethodSwitchAdjustmentIsApartWithItsPaymentOnBothSurfaces(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-01-01']);
        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', ['expense_methods' => ['actual'], 'chosen_method' => 'actual']);
        self::assertResponseIsSuccessful();
        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2027', ['expense_methods' => ['lump_60'], 'chosen_method' => 'lump_60']);
        self::assertResponseIsSuccessful();
        Clock::set(new MockClock('2027-03-10 10:00:00', 'UTC'));
        $later = $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->ownerId, 'Taxes 2027', [ApiScope::from('taxes:write')])->secret);
        $open = new OpenAmount('invoicing.payments', new Subject('invoice', 'open', null), AmountKind::Income, Money::ofMinor(50_000_00, Currency::of('CZK')), Money::ofMinor(50_000_00, Currency::of('CZK')), Money::ofMinor(0, Currency::of('CZK')));
        self::getContainer()->set(IssuedPaymentAmounts::class, new DatedAmountSource('invoicing.payments', [], ['2026-12-31' => [$open]]));

        $this->get('/api/v1/taxes-and-contributions/estimate?year=2027', secret: $later);
        self::assertResponseIsSuccessful();
        $czk = static fn (int $minor): array => ['amount_minor' => $minor, 'currency' => 'CZK', 'minor_units' => 2];
        self::assertSame([
            'adjusted_year' => 2026,
            'from_method' => 'actual',
            'into' => 'lump_60',
            'open_on' => '2026-12-31',
            'receivables' => $czk(50_000_00),
            'payables' => $czk(0),
            'base_change' => $czk(50_000_00),
            'previous_tax_base' => $czk(0),
            'rate' => '15',
            'tax' => $czk(7_500_00),
            'due_on' => '2028-05-02',
            'source_url' => 'https://www.zakonyprolidi.cz/cs/1992-586',
        ], $this->at('data', 'to_date', 'switch_adjustment'));
        $payments = (array) $this->at('data', 'payments');
        $index = $this->index($payments, 'income_tax.switch_adjustment.2027');
        self::assertSame(['switch_adjustment', '2027', 'out', '2028-05-02', $czk(7_500_00), 'expected'], [$this->at('data', 'payments', $index, 'kind'), $this->at('data', 'payments', $index, 'period'), $this->at('data', 'payments', $index, 'direction'), $this->at('data', 'payments', $index, 'due_on'), $this->at('data', 'payments', $index, 'amount'), $this->at('data', 'payments', $index, 'state')]);
        self::assertContains('switch_adjustment', (array) $this->at('data', 'warnings'));
        self::assertContains('switch_adjustment_insurance', (array) $this->at('data', 'unverified'));
        self::assertSame($czk(0), $this->at('data', 'to_date', 'methods', 0, 'totals', 'income'), 'Never inside the totals.');
        $estimate = $this->json()['data'];

        $call = $this->tool($later, 'foresi_tax_estimate', ['year' => 2027]);
        self::assertEquals($estimate, $this->in($call, 'structuredContent', 'data'));
        self::assertStringContainsString('Method change: 2026 adjusted by ', $this->text($call));
        self::assertStringContainsString('due 2028-05-02.', $this->text($call));
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
