<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Infrastructure;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\CreateProductInput;
use Foresi\Catalog\Application\RestoreProductInput;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Infrastructure\Mcp\GetProductTool;
use Foresi\Catalog\Infrastructure\Mcp\SearchProductsTool;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The catalog over REST and MCP: the writes declared once with #[Operation],
 * the reads under catalog:read, and a guest refused on both surfaces.
 */
final class CatalogApiTest extends ApiTestCase
{
    private const array RETAINER = [
        'name' => 'Marketing retainer',
        'billing_kind' => 'recurring',
        'unit' => 'month',
        'unit_price' => '25000.00',
        'recurrence_unit' => 'month',
        'interval' => 1,
        'cycles' => 12,
    ];

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('catalog:write')]);
    }

    public function testCreatesUpdatesArchivesAndRestoresAProduct(): void
    {
        $this->send('POST', '/api/v1/catalog/products', self::RETAINER);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/catalog/products/'.$id);
        $created = $this->json()['data'];
        self::assertSame(['amount_minor' => 2500000, 'currency' => 'CZK', 'minor_units' => 2], $this->at('data', 'unit_price'));
        self::assertSame(['recurring', 'month', 1, 12, false], [$this->at('data', 'billing_kind'), $this->at('data', 'recurrence_unit'), $this->at('data', 'interval'), $this->at('data', 'cycles'), $this->at('data', 'archived')]);
        $this->get('/api/v1/catalog/products/'.$id, secret: $this->writer);
        self::assertSame($created, $this->json()['data']);

        $this->send('PATCH', '/api/v1/catalog/products/'.$id, ['unit_price' => null, 'cycles' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame([null, null, 'Marketing retainer'], [$this->at('data', 'unit_price'), $this->at('data', 'cycles'), $this->at('data', 'name')]);

        $this->send('POST', '/api/v1/catalog/products/'.$id.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->get('/api/v1/catalog/products', [], $this->writer);
        self::assertSame([], $this->json()['data']);
        $this->get('/api/v1/catalog/products', ['archived' => 'true', 'q' => 'retainer'], $this->writer);
        $listed = $this->json()['data'];
        self::assertIsArray($listed);
        self::assertSame([$id], array_column($listed, 'id'));

        $this->send('POST', '/api/v1/catalog/products/'.$id.'/restore');
        self::assertFalse($this->at('data', 'archived'));
    }

    public function testARefusalNamesTheParameterAsTheClientSendsIt(): void
    {
        $this->send('POST', '/api/v1/catalog/products', ['interval' => null] + self::RETAINER);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'interval', 'reason' => 'interval_required']);

        $this->send('POST', '/api/v1/catalog/products', ['name' => 'Roof audit', 'cycles' => 3]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'cycles', 'reason' => 'one_off_recurrence']);

        $this->get('/api/v1/catalog/products/0192f000-0000-7000-8000-000000000000', secret: $this->writer);
        $this->assertProblem(404, 'not_found');
    }

    public function testTheToolsWriteAndReadAsRestDoes(): void
    {
        $created = $this->tool('foresi_create_product', ['name' => 'Roof audit', 'unit' => 'piece', 'unit_price' => '12000.00', 'idempotency_key' => 'create-roof-audit']);
        self::assertFalse($created['isError'] ?? false);
        $id = $this->in($created, 'structuredContent', 'data', 'id');
        self::assertIsString($id);

        $found = $this->tool('foresi_search_products', ['query' => 'roof']);
        $items = $this->in($found, 'structuredContent', 'data', 'items');
        self::assertIsArray($items);
        self::assertSame([$id], array_column($items, 'id'));
        $one = $this->tool('foresi_get_product', ['id' => $id]);
        self::assertSame(['one_off', 1200000], [$this->in($one, 'structuredContent', 'data', 'billing_kind'), $this->in($one, 'structuredContent', 'data', 'unit_price', 'amount_minor')]);
        $this->get('/api/v1/catalog/products/'.$id, secret: $this->writer);
        self::assertSame($this->in($one, 'structuredContent', 'data'), $this->json()['data']);
    }

    public function testScopesDecideWhatATokenDoes(): void
    {
        // The default token reads everything and writes nothing.
        $this->get('/api/v1/catalog/products', [], $this->secret);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/catalog/products', self::RETAINER, $this->secret);
        $this->assertProblem(403, 'insufficient_scope');

        $firm = $this->issued([ApiScope::from('firm:read')]);
        $this->get('/api/v1/catalog/products', [], $firm);
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestIsRefused(): void
    {
        // A guest holds no personal token (ADR 0041), and the catalog's tools are hidden from a guest's OAuth connection.
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        try {
            self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($guest->id(), 'Guest', [ApiScope::from('catalog:write')]);
            self::fail('A guest was given a token.');
        } catch (ApiTokenRefused) {
        }
        foreach ([SearchProductsTool::class, GetProductTool::class] as $tool) {
            $read = self::getContainer()->get($tool);
            self::assertInstanceOf(StaffOnlyTool::class, $read);
            self::assertTrue($read->staffOnly());
        }
        foreach ([CreateProductInput::class, UpdateProductInput::class, ArchiveProductInput::class, RestoreProductInput::class] as $input) {
            self::assertTrue(new \ReflectionClass($input)->getAttributes(Operation::class)[0]->newInstance()->staffOnly, $input);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
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
