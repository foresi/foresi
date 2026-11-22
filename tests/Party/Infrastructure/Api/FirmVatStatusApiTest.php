<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Api;

use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The firm's VAT statuses over time on REST and MCP: added and removed by the
 * owner with contacts:write, read with the firm.
 */
final class FirmVatStatusApiTest extends ApiTestCase
{
    private string $owner;
    private string $memberWriter;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $owner = $this->fixtures->user('owner@example.com');
        $this->fixtures->join($owner, Role::Owner);
        $this->owner = $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($owner->id(), 'Firm', [ApiScope::from('contacts:write')])->secret);
        $this->memberWriter = $this->issued([ApiScope::from('contacts:write')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheOwnerAddsAndRemovesAStatusAndReadsItWithTheFirm(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'not_registered', 'valid_from' => '2027-01-01']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame(['id' => $id, 'status' => 'not_registered', 'period_length' => null, 'valid_from' => '2027-01-01'], $this->json()['data']);

        $this->get('/api/v1/firm', secret: $this->owner);
        self::assertResponseIsSuccessful();
        self::assertSame('registered', $this->at('data', 'vat_status'), 'today the kept status still holds');
        $statuses = $this->at('data', 'vat_statuses');
        self::assertIsArray($statuses);
        self::assertCount(2, $statuses);
        self::assertSame(['registered', 'month', null], [$this->at('data', 'vat_statuses', 0, 'status'), $this->at('data', 'vat_statuses', 0, 'period_length'), $this->at('data', 'vat_statuses', 0, 'valid_from')]);
        self::assertSame(['id' => $id, 'status' => 'not_registered', 'period_length' => null, 'valid_from' => '2027-01-01'], $this->at('data', 'vat_statuses', 1));
        $firm = $this->json()['data'];

        $call = $this->tool($this->owner, 'foresi_get_firm', []);
        self::assertFalse($call['isError'] ?? false);
        self::assertEquals($firm, $this->in($call, 'structuredContent', 'data'));

        $this->send($this->owner, 'DELETE', '/api/v1/firm/vat-statuses/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $id, 'removed' => true], $this->json()['data']);
        $this->send($this->owner, 'DELETE', '/api/v1/firm/vat-statuses/'.$id);
        $this->assertProblem(404, 'not_found');
    }

    public function testTheOwnerAddsAndRemovesAStatusWithTheTools(): void
    {
        $added = $this->tool($this->owner, 'foresi_add_firm_vat_status', ['status' => 'registered', 'period_length' => 'quarter', 'valid_from' => '2027-01-01']);
        self::assertFalse($added['isError'] ?? false);
        self::assertSame(['registered', 'quarter', '2027-01-01'], [$this->in($added, 'structuredContent', 'data', 'status'), $this->in($added, 'structuredContent', 'data', 'period_length'), $this->in($added, 'structuredContent', 'data', 'valid_from')]);
        self::assertStringContainsString('The firm is registered, with VAT periods of a quarter, from 2027-01-01.', $this->text($added));
        $id = $this->in($added, 'structuredContent', 'data', 'id');
        self::assertIsString($id);

        $removed = $this->tool($this->owner, 'foresi_remove_firm_vat_status', ['vat_status_id' => $id]);
        self::assertFalse($removed['isError'] ?? false);
        self::assertSame(['id' => $id, 'removed' => true], $this->in($removed, 'structuredContent', 'data'));

        $this->get('/api/v1/firm', secret: $this->owner);
        $statuses = $this->at('data', 'vat_statuses');
        self::assertIsArray($statuses);
        self::assertCount(1, $statuses);
    }

    public function testRefusalsNameTheirReason(): void
    {
        $this->send($this->owner, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'registered', 'valid_from' => '2026-11-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'period_length', 'reason' => 'period_length_required']);
        $this->send($this->owner, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'not_registered', 'period_length' => 'month', 'valid_from' => '2026-11-12']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'period_length', 'reason' => 'period_length_not_allowed']);
        $this->send($this->owner, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'registered', 'period_length' => 'month', 'valid_from' => '12.11.2026']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'valid_from', 'reason' => 'date_invalid']);

        $this->send($this->owner, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12']);
        self::assertResponseStatusCodeSame(201);
        $this->send($this->owner, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'not_registered', 'valid_from' => '2026-11-12']);
        $this->assertProblem(409, 'same_day');

        $this->get('/api/v1/firm', secret: $this->owner);
        $first = $this->at('data', 'vat_statuses', 0, 'id');
        $second = $this->at('data', 'vat_statuses', 1, 'id');
        self::assertIsString($first);
        self::assertIsString($second);
        $this->send($this->owner, 'DELETE', '/api/v1/firm/vat-statuses/'.$first);
        self::assertResponseIsSuccessful();
        $this->send($this->owner, 'DELETE', '/api/v1/firm/vat-statuses/'.$second);
        $this->assertProblem(409, 'last_stretch');
    }

    public function testAMemberIsRefusedWithOwnerOnly(): void
    {
        $this->send($this->memberWriter, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12']);
        $this->assertProblem(403, 'owner_only');
        $this->send($this->memberWriter, 'DELETE', '/api/v1/firm/vat-statuses/'.$this->firmId);
        $this->assertProblem(403, 'owner_only');

        $add = $this->tool($this->memberWriter, 'foresi_add_firm_vat_status', ['status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12']);
        self::assertTrue($add['isError'] ?? false);
        self::assertSame('owner_only', $this->in($add, 'structuredContent', 'error', 'code'));
        $remove = $this->tool($this->memberWriter, 'foresi_remove_firm_vat_status', ['vat_status_id' => $this->firmId]);
        self::assertTrue($remove['isError'] ?? false);
        self::assertSame('owner_only', $this->in($remove, 'structuredContent', 'error', 'code'));

        // The member still reads the statuses with the firm.
        $this->get('/api/v1/firm', secret: $this->memberWriter);
        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $this->firmId, 'status' => 'registered', 'period_length' => 'month', 'valid_from' => null]], $this->at('data', 'vat_statuses'));
    }

    public function testATokenWithoutTheWriteScopeIsRefusedForTheScope(): void
    {
        $this->send($this->secret, 'POST', '/api/v1/firm/vat-statuses', ['status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12']);

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
