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
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The owner profile over REST and MCP: the writes declared once with
 * #[Operation], the read under taxes:read, both for the owner only (B7). The
 * estimate by the Czech rules is in Integration/Country.
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
    private string $memberWriter;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $owner = $this->fixtures->user('owner@example.com');
        $this->fixtures->join($owner, Role::Owner);
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

    public function testTheOwnerBuildsTheProfileAndReadsItBackOnBothSurfaces(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-11-12']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['activity', 'main', '2026-11-12', null], [$this->at('data', 'kind'), $this->at('data', 'value'), $this->at('data', 'valid_from'), $this->at('data', 'base')]);
        $statusId = $this->at('data', 'id');
        self::assertIsString($statusId);

        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'sickness_insurance', 'value' => 'on', 'valid_from' => '2026-01-01', 'base' => '10000.00']);
        self::assertSame(['amount_minor' => 1000000, 'currency' => 'CZK', 'minor_units' => 2], $this->at('data', 'base'));

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', self::YEAR);
        self::assertResponseIsSuccessful();
        self::assertSame(2026, $this->at('data', 'year'));
        self::assertSame(['months' => 6, 'ztp_p' => false, 'low_income' => false], $this->at('data', 'spouse'));
        self::assertSame([['type' => 'pension_products', 'amount' => ['amount_minor' => 2400000, 'currency' => 'CZK', 'minor_units' => 2]]], $this->at('data', 'deductions'));
        self::assertSame(['amount_minor' => 12000000, 'currency' => 'CZK', 'minor_units' => 2], $this->at('data', 'other_bases', 'employment'));
        $year = $this->json()['data'];

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/authorities/health', ['account' => '1234567890/0300', 'variable_symbol' => '8501011234']);
        self::assertSame(['authority' => 'health', 'account' => '1234567890/0300', 'variable_symbol' => '8501011234'], $this->json()['data']);

        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->owner);
        self::assertResponseIsSuccessful();
        $profile = $this->json()['data'];
        self::assertSame(['activity', 'vat_payer', 'sickness_insurance'], [$this->at('data', 'statuses', 0, 'kind'), $this->at('data', 'statuses', 1, 'kind'), $this->at('data', 'statuses', 2, 'kind')]);
        self::assertSame(['no', '2000-01-01'], [$this->at('data', 'statuses', 1, 'value'), $this->at('data', 'statuses', 1, 'valid_from')], 'VAT payer is the firm\'s.');
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
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'secondary', 'valid_from' => '2026-01-01']);
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-01-01']);
        $this->assertProblem(409, 'status_day_taken');

        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'sickness_insurance', 'value' => 'on', 'valid_from' => '2026-01-01']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'base', 'reason' => 'base_required']);

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', ['children' => [['label' => 'Elder daughter', 'birth_date' => '2019-05-14', 'order' => 1, 'months' => 13]]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'children', 'reason' => 'months_invalid']);

        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', ['previous_activity_months' => 0]);
        $this->assertProblem(400, 'invalid_parameter', ['reason' => 'activity_months_invalid']);
        $this->send($this->owner, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', ['previous_activity_months' => 5]);
        self::assertSame(5, $this->at('data', 'previous_year', 'activity_months'));
    }

    public function testTheOwnerReadsTheAmountsOfAYearAndABadYearNamesItsParameter(): void
    {
        $this->get('/api/v1/taxes-and-contributions/amounts?year=2025', secret: $this->owner);
        self::assertResponseIsSuccessful();
        self::assertSame(['2025-01-01', '2025-12-31'], [$this->at('data', 'from'), $this->at('data', 'to')]);
        self::assertSame(['gross' => ['amount_minor' => 0, 'currency' => 'CZK', 'minor_units' => 2], 'net' => ['amount_minor' => 0, 'currency' => 'CZK', 'minor_units' => 2], 'vat' => ['amount_minor' => 0, 'currency' => 'CZK', 'minor_units' => 2], 'counted' => ['amount_minor' => 0, 'currency' => 'CZK', 'minor_units' => 2]], $this->at('data', 'totals', 'income'));
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

    public function testAddingAVatPayerStatusIsRefusedWithVatPayerOnFirm(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'vat_payer', 'value' => 'yes', 'valid_from' => '2026-11-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'kind', 'reason' => 'vat_payer_on_firm']);
        $detail = $this->at('detail');
        self::assertIsString($detail);
        self::assertStringContainsString('POST /api/v1/firm/vat-statuses', $detail);

        $call = $this->tool($this->owner, 'foresi_add_tax_status', ['kind' => 'vat_payer', 'value' => 'yes', 'valid_from' => '2026-11-12']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('vat_payer_on_firm', $this->in($call, 'structuredContent', 'error', 'details', 'reason'));
        self::assertStringContainsString('foresi_add_firm_vat_status', $this->text($call));

        // Nor is the firm's own stretch removed here.
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->owner);
        $id = $this->at('data', 'statuses', 0, 'id');
        self::assertIsString($id);
        self::assertStringStartsWith('firm-vat:', $id);
        $this->send($this->owner, 'DELETE', '/api/v1/taxes-and-contributions/profile/statuses/'.$id);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'status_id', 'reason' => 'vat_payer_on_firm']);
    }

    public function testAMemberIsRefusedWithOwnerOnly(): void
    {
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->memberWriter);
        $this->assertProblem(403, 'owner_only');
        // A token that reads everything still reads no profile of a member.
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->secret);
        $this->assertProblem(403, 'owner_only');

        $this->send($this->memberWriter, 'POST', '/api/v1/taxes-and-contributions/profile/statuses', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-11-12']);
        $this->assertProblem(403, 'owner_only');
        $this->send($this->memberWriter, 'PUT', '/api/v1/taxes-and-contributions/profile/years/2026', self::YEAR);
        $this->assertProblem(403, 'owner_only');
        $this->send($this->memberWriter, 'PUT', '/api/v1/taxes-and-contributions/profile/authorities/health', ['account' => '1/0300']);
        $this->assertProblem(403, 'owner_only');

        $read = $this->tool($this->memberWriter, 'foresi_get_tax_profile', []);
        self::assertTrue($read['isError'] ?? false);
        self::assertSame('owner_only', $this->in($read, 'structuredContent', 'error', 'code'));
        $write = $this->tool($this->memberWriter, 'foresi_add_tax_status', ['kind' => 'activity', 'value' => 'main', 'valid_from' => '2026-11-12']);
        self::assertTrue($write['isError'] ?? false);
        self::assertSame('owner_only', $this->in($write, 'structuredContent', 'error', 'code'));

        // The token without the scope is refused for the scope first.
        $this->get('/api/v1/taxes-and-contributions/profile', secret: $this->issued([ApiScope::from('firm:read')]));
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
}
