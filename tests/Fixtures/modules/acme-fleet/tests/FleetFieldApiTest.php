<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The fleet's custom fields of a contact over REST and MCP (module API 1.6): listed by
 * GET /api/v1/fields, written by contacts.create and foresi_update_contact, read back
 * under custom_fields by key, and a value its kind does not take refused at its key.
 */
final class FleetFieldApiTest extends ApiTestCase
{
    private const array KEYS = ['acme_fleet_industry', 'acme_fleet_contract_until', 'acme_fleet_account_code', 'acme_fleet_services', 'acme_fleet_fleet_manager'];

    private const array PERSON = [
        'kind' => 'person',
        'display_name' => 'Petra Example',
        'street' => 'Example Street 1',
        'city' => 'Example City',
        'postal_code' => '100 00',
        'country_code' => 'CZ',
    ];

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('contacts:write')]);
    }

    public function testTheFleetsFieldsAreListed(): void
    {
        $this->get('/api/v1/fields', ['record' => 'contact'], $this->writer);

        self::assertResponseIsSuccessful();
        $fields = $this->at('data');
        self::assertIsArray($fields);
        self::assertSame(self::KEYS, array_column($fields, 'key'));
        self::assertSame(['select', 'acme-fleet', ['logistics', 'construction', 'retail']], [$this->at('data', 0, 'kind'), $this->at('data', 0, 'module'), array_column((array) $this->at('data', 0, 'options'), 'key')]);
    }

    public function testTheFleetsFieldsAreWrittenAndReadOverRestAndMcp(): void
    {
        $this->send('POST', '/api/v1/contacts', self::PERSON + ['custom_fields' => ['acme_fleet_industry' => 'logistics', 'acme_fleet_contract_until' => '2027-06-30', 'acme_fleet_services' => ['leasing'], 'acme_fleet_fleet_manager' => $this->user->id()]]);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame([
            'acme_fleet_industry' => 'logistics',
            'acme_fleet_contract_until' => '2027-06-30',
            'acme_fleet_account_code' => null,
            'acme_fleet_services' => ['leasing'],
            'acme_fleet_fleet_manager' => $this->user->id(),
        ], $this->at('data', 'custom_fields'));

        $call = $this->tool('foresi_update_contact', ['contact_id' => $id, 'custom_fields' => ['acme_fleet_account_code' => 'FL-7', 'acme_fleet_industry' => null]]);
        self::assertFalse($call['isError'] ?? false, json_encode($call, \JSON_THROW_ON_ERROR));
        $this->get('/api/v1/contacts/'.$id, secret: $this->writer);
        self::assertSame(['FL-7', null, '2027-06-30'], [$this->at('data', 'custom_fields', 'acme_fleet_account_code'), $this->at('data', 'custom_fields', 'acme_fleet_industry'), $this->at('data', 'custom_fields', 'acme_fleet_contract_until')]);

        $this->send('PATCH', '/api/v1/contacts/'.$id, ['custom_fields' => ['acme_fleet_industry' => 'farming']]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.acme_fleet_industry', 'reason' => 'invalid_value']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
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
        if (null === $this->session && 'initialize' !== $method) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params], \JSON_THROW_ON_ERROR));
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
