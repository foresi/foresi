<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpSetupTest extends WebTestCase
{
    public function testTheSetupFileIsPublicMarkdownWithThisInstallationsAddresses(): void
    {
        $client = self::createClient();
        $client->request('GET', '/mcp-setup.md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=UTF-8');
        $text = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("---\ntitle: Install the Foresi MCP server\nserver: http://localhost/mcp\n", $text);
        self::assertStringContainsString('claude mcp add --transport http foresi http://localhost/mcp --header "Authorization: Bearer $FORESI_TOKEN"', $text);
        self::assertStringContainsString('http://localhost/settings/api', $text);
        self::assertStringContainsString('`Authorization: Bearer <token>`', $text);
        self::assertStringNotContainsString('&lt;', $text);
        self::assertStringContainsString('Call the tool `whoami`', $text);
        self::assertSame(['mcpServers' => ['foresi' => ['url' => 'http://localhost/mcp', 'headers' => ['Authorization' => 'Bearer <token>']]]], $this->json($text, 1));
        self::assertStringContainsString('"url": "http://localhost/mcp"', json_encode($this->json($text, 2), \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    public function testLlmsTxtPointsToTheSetupFile(): void
    {
        $client = self::createClient();
        $client->request('GET', '/llms.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $text = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("# Foresi\n", $text);
        self::assertStringContainsString('[Install the Foresi MCP server](http://localhost/mcp-setup.md)', $text);
    }

    /**
     * The JSON configuration in the n-th json code fence of the file (0 is the first).
     *
     * @return array<mixed>
     */
    private function json(string $text, int $n): array
    {
        self::assertGreaterThan($n, preg_match_all('/```json\n(.*?)\n```/s', $text, $blocks));
        $config = json_decode($blocks[1][$n], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        return $config;
    }
}
