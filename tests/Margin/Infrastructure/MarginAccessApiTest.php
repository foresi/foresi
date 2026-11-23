<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Infrastructure;

use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Who may work with the margin over REST and MCP: a token needs its scope,
 * and a guest never does. It needs no module that has costs, so it also runs
 * where Margin is installed alone; the operations themselves are tested with
 * Purchasing's received documents.
 */
final class MarginAccessApiTest extends ApiTestCase
{
    private ?string $session = null;

    public function testATokenWithoutTheScopeIsRefused(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $reader = $this->issued([ApiScope::from('margin:read')]);
        $other = $this->issued([ApiScope::from('contacts:read')]);

        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => null], $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => 'own'], $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/margin/documents/'.$id, secret: $other);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/margin/documents/'.$id, secret: $reader);
        $this->assertProblem(404, 'unknown_document');
    }

    public function testAGuestIsRefused(): void
    {
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        $secret = $this->flushed(function () use ($guest): string {
            $now = self::getContainer()->get(ClockInterface::class)->now();
            $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
            self::getContainer()->get(OAuthStore::class)->addClient($client);
            $secret = ApiTokenSecret::generateOAuth();
            self::getContainer()->get(ApiTokens::class)->add(new ApiToken($guest->id(), 'Example Agent', [ApiScope::write()], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $client->id()));

            return $secret;
        });

        // Over REST a guest's token is never accepted (ADR 0041).
        $this->get('/api/v1/margin/documents/'.Uuid::v7()->toRfc4122(), secret: $secret);
        self::assertResponseStatusCodeSame(401);

        if ([] === self::getContainer()->get(GuestScopes::class)->offered()) {
            $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
            self::assertResponseStatusCodeSame(401);

            return;
        }
        // The connection works, but no margin tool is listed or answers.
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $tools = array_column((array) $this->in($this->mcp($secret, 'tools/list'), 'result', 'tools'), 'name');
        self::assertSame([], array_values(array_filter($tools, static fn (mixed $name): bool => \is_string($name) && str_contains($name, 'allocation'))));
        $call = $this->in($this->mcp($secret, 'tools/call', ['name' => 'foresi_get_document_allocation', 'arguments' => ['document_id' => Uuid::v7()->toRfc4122()]]), 'result');
        self::assertIsArray($call);
        self::assertTrue($call['isError'] ?? false);
    }

    private function line(string $documentId, string $key): string
    {
        return '/api/v1/margin/documents/'.$documentId.'/lines/'.$key.'/allocation';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body, string $secret): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
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
