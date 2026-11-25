<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Acme\Fleet\Domain\Vehicle;
use Doctrine\DBAL\Connection;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The operation over REST and MCP, the read tool, the event and the setting of
 * a module of another vendor, in the application it is installed in.
 */
final class FleetApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('acme_fleet_vehicles:write')]);
    }

    public function testAddsAVehicleOverRestWithItsEvent(): void
    {
        $this->send('POST', '/api/v1/acme-fleet/vehicles', ['name' => 'Delivery van', 'plate' => '1ab 2345', 'driver_contact_id' => $this->customerId]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Delivery van', '1AB 2345', $this->customerId], [$this->at('data', 'name'), $this->at('data', 'plate'), $this->at('data', 'driver_contact_id')]);
        $recorded = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM event_log WHERE name = ? AND subject_id = ?', ['acme-fleet.vehicle.added', $this->at('data', 'id')]);
        self::assertSame(1, (int) $recorded);
    }

    public function testTheToolsAreNamedAfterTheModule(): void
    {
        $listed = $this->in($this->mcp('tools/list'), 'result', 'tools');
        self::assertIsArray($listed);
        $names = array_column($listed, 'name');
        self::assertContains('acme_fleet_add_vehicle', $names);
        self::assertContains('acme_fleet_list_vehicles', $names);

        $added = $this->tool('acme_fleet_add_vehicle', ['name' => 'Pick-up', 'plate' => '2CD 3456', 'idempotency_key' => 'add-pick-up']);
        self::assertFalse($added['isError'] ?? false);
        $listing = $this->tool('acme_fleet_list_vehicles', []);
        $items = $this->in($listing, 'structuredContent', 'data', 'items');
        self::assertIsArray($items);
        self::assertSame(['2CD 3456'], array_column($items, 'plate'));
    }

    public function testAPlateIsInTheFleetOnce(): void
    {
        $this->send('POST', '/api/v1/acme-fleet/vehicles', ['name' => 'Delivery van', 'plate' => '1AB 2345']);
        $this->send('POST', '/api/v1/acme-fleet/vehicles', ['name' => 'Another van', 'plate' => '1AB 2345']);

        $this->assertProblem(409, 'plate_taken');
    }

    public function testTheFleetHoldsAtMostTheSetting(): void
    {
        $most = self::getContainer()->getParameter('acme_fleet.max_vehicles');
        self::assertSame(25, $most);
        $this->flushed(function () use ($most): void {
            for ($i = 0; $i < $most; ++$i) {
                $this->em()->persist(new Vehicle(\sprintf('0192f1a4-0000-7000-8000-%012d', $i), 'Van '.$i, 'VAN '.$i, null, new \DateTimeImmutable()));
            }
        });

        $this->send('POST', '/api/v1/acme-fleet/vehicles', ['name' => 'One too many', 'plate' => 'MANY 1']);

        $this->assertProblem(409, 'fleet_full');
    }

    public function testTheScopeDecidesWhatATokenDoes(): void
    {
        // The default token reads everything and writes nothing.
        $this->send('POST', '/api/v1/acme-fleet/vehicles', ['name' => 'Delivery van', 'plate' => '1AB 2345'], $this->secret);

        $this->assertProblem(403, 'insufficient_scope');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], content: json_encode($body, \JSON_THROW_ON_ERROR));
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
