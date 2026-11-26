<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A tool called through the real MCP server at /mcp, in a session of its own,
 * for an API test case with a $client: the way an agent runs an inbox action.
 *
 * @property KernelBrowser $client
 */
trait McpToolCalls
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed> the result of tools/call: isError, content and structuredContent
     */
    private function callTool(string $secret, string $name, array $arguments): array
    {
        $session = null;
        $this->mcpSessionRequest($secret, $session, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $result = $this->mcpSessionRequest($secret, $session, 'tools/call', ['name' => $name, 'arguments' => $arguments])['result'] ?? null;
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcpSessionRequest(string $secret, ?string &$session, string $method, array $params): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $session ??= $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
