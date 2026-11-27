<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Integration\Country;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The source of a Czech firm's exchange rates over REST and MCP: the ČNB by
 * default, the ECB once a member chooses it, nothing the Czech rules do not
 * allow, and a change recorded as an event.
 */
final class RateSourceApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('exchange_rates:write')]);
    }

    public function testACzechFirmConvertsAtTheCnbUntilItChooses(): void
    {
        $this->get('/api/v1/exchange-rate/source', secret: $this->writer);

        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertIsArray($data);
        self::assertSame(['cnb', false, 'cnb', ['cnb', 'ecb'], 'CZ'], [$data['source'], $data['chosen'], $data['default'], $data['allowed'], $data['country']]);
        self::assertIsArray($data['installed']);
        self::assertSame(['cnb', 'ecb'], array_slice($data['installed'], 0, 2));

        $call = $this->tool($this->writer, 'foresi_get_exchange_rate_source', []);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame($data, $this->in($call, 'structuredContent', 'data'));
    }

    public function testAMemberChoosesTheEcbAndBackOnBothSurfacesWithAnEventEachTime(): void
    {
        $this->send('PUT', '/api/v1/exchange-rate/source', ['source' => ' ecb '], $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame(['ecb', true, 'cnb'], [$this->at('data', 'source'), $this->at('data', 'chosen'), $this->at('data', 'default')]);
        self::assertSame('ecb', self::getContainer()->get(RateSource::class)->name());

        // Choosing what is in use changes nothing worth an event.
        $this->send('PUT', '/api/v1/exchange-rate/source', ['source' => 'ecb'], $this->writer);
        self::assertResponseIsSuccessful();

        $call = $this->tool($this->writer, 'foresi_set_exchange_rate_source', ['source' => 'cnb']);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame(['cnb', true], [$this->in($call, 'structuredContent', 'data', 'source'), $this->in($call, 'structuredContent', 'data', 'chosen')]);

        self::assertSame([['ecb', ['source' => 'ecb', 'previous' => 'cnb']], ['cnb', ['source' => 'cnb', 'previous' => 'ecb']]], $this->events('exchange-rate.source.changed'));
    }

    public function testASourceThatIsNotInstalledOrNotAllowedIsRefused(): void
    {
        $this->send('PUT', '/api/v1/exchange-rate/source', ['source' => 'acme'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'source', 'reason' => 'source_unknown']);

        $this->send('PUT', '/api/v1/exchange-rate/source', ['source' => str_repeat('x', 17)], $this->writer);
        self::assertResponseStatusCodeSame(400);

        $this->get('/api/v1/exchange-rate/source', secret: $this->writer);
        self::assertSame([false, []], [$this->at('data', 'chosen'), $this->events('exchange-rate.source.changed')]);
    }

    public function testATokenWithoutTheScopeIsRefused(): void
    {
        $this->get('/api/v1/exchange-rate/source', secret: $this->issued([ApiScope::from('firm:read')]));
        $this->assertProblem(403, 'insufficient_scope');
        $this->send('PUT', '/api/v1/exchange-rate/source', ['source' => 'ecb'], $this->issued([ApiScope::from('exchange_rates:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    /**
     * The events of one name, oldest first, each as its subject id and facts.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private function events(string $name): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT '.EventLogTable::SUBJECT_ID.', '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        return array_map(static function (array $row): array {
            self::assertIsString($row[EventLogTable::DATA]);
            self::assertIsString($row[EventLogTable::SUBJECT_ID]);
            $data = json_decode($row[EventLogTable::DATA], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            $facts = [];
            foreach ($data as $fact => $value) {
                $facts[(string) $fact] = $value;
            }

            return [$row[EventLogTable::SUBJECT_ID], $facts];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body, string $secret): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
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
