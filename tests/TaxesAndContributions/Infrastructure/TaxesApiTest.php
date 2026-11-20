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
