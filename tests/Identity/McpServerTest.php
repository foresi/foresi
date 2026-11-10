<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Application\ManageApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\Mcp\ReadToolCall;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The MCP server over Streamable HTTP at /mcp: a client signs in with its
 * personal API token on every request, finds the Foresi tools, all of them
 * read-only, and calls one. No request sets a cookie.
 */
final class McpServerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private TenantId $tenant;
    private string $secret;
    private ?string $session = null;
    private string $protocol = '2025-06-18';

    public function testWithoutATokenNothingIsServed(): void
    {
        $this->start();

        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: $this->message('initialize', $this->initialize()));

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    /**
     * Claude Desktop and Claude Code speak one of these.
     */
    #[DataProvider('protocols')]
    public function testAClientFindsTheReadOnlyToolsAndCallsOne(string $protocol): void
    {
        $this->protocol = $protocol;
        $this->start();

        $initialized = $this->rpc('initialize', $this->initialize());
        self::assertSame('foresi', $this->at($initialized, 'result', 'serverInfo', 'name'));
        self::assertSame(self::getContainer()->getParameter('app.version'), $this->at($initialized, 'result', 'serverInfo', 'version'));
        self::assertSame($this->protocol, $this->at($initialized, 'result', 'protocolVersion'));
        self::assertStringContainsString('These tools only read', $this->text($initialized, 'result', 'instructions'));
        self::assertNotNull($this->session);
        $this->notify('notifications/initialized');

        $tools = $this->at($this->rpc('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        self::assertContains('foresi_whoami', array_column($tools, 'name'));
        foreach ($tools as $tool) {
            self::assertStringStartsWith('foresi_', $this->text($tool, 'name'));
            self::assertSame(['title' => $this->at($tool, 'title'), 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], $this->at($tool, 'annotations'));
        }

        $call = $this->at($this->rpc('tools/call', ['name' => 'foresi_whoami', 'arguments' => new \stdClass()]), 'result');
        self::assertFalse($this->at($call, 'isError'));
        self::assertSame(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $this->at($call, 'structuredContent', 'untrusted_text_notice'));
        self::assertSame(['id' => $this->user->id(), 'email' => 'testing@example.com'], $this->at($call, 'structuredContent', 'data', 'user'));
        self::assertSame(['id' => $this->tenant->toString(), 'name' => 'Tenant A'], $this->at($call, 'structuredContent', 'data', 'tenant'));
        self::assertSame('Reporting', $this->at($call, 'structuredContent', 'data', 'token', 'name'));
        self::assertSame(['read'], $this->at($call, 'structuredContent', 'data', 'token', 'scopes'));
        $text = $this->text($call, 'content', '0', 'text');
        self::assertStringStartsWith(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $text);
        self::assertStringContainsString('"email":"testing@example.com"', $text);
    }

    public function testATokenWithoutTheReadScopeGetsAnErrorResult(): void
    {
        $this->start([ApiScope::Write]);

        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');
        $call = $this->at($this->rpc('tools/call', ['name' => 'foresi_whoami', 'arguments' => new \stdClass()]), 'result');

        self::assertTrue($this->at($call, 'isError'));
        self::assertSame('insufficient_scope', $this->at($call, 'structuredContent', 'error', 'code'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protocols(): iterable
    {
        yield '2025-06-18' => ['2025-06-18'];
        yield '2025-11-25' => ['2025-11-25'];
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function start(array $scopes = [ApiScope::Read]): void
    {
        $this->client = self::createClient();
        // The test database lives in memory, so every request must share the kernel.
        $this->client->disableReboot();
        $fixtures = new IdentityFixtures($this->em(), $this->context());
        $fixtures->rebuildSchema();
        $this->user = $fixtures->user();
        $this->tenant = $fixtures->tenant('Tenant A');
        $fixtures->join($this->user, $this->tenant, Role::Member);
        $this->context()->switchTo($this->tenant);
        try {
            $this->secret = self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Reporting', $scopes)->secret;
        } finally {
            $this->context()->reset();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return ['protocolVersion' => $this->protocol, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $this->post($this->message($method, $params, 1));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame([], $response->headers->getCookies());
        self::assertFalse($response->headers->has('Set-Cookie'));
        $this->session ??= $response->headers->get('Mcp-Session-Id');
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('error', $body, (string) $response->getContent());

        return $body;
    }

    private function notify(string $method): void
    {
        $this->post($this->message($method));

        self::assertResponseStatusCodeSame(202);
    }

    private function post(string $content): void
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->secret,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => $this->protocol,
        ];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: $content);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function message(string $method, array $params = [], ?int $id = null): string
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if (null !== $id) {
            $message['id'] = $id;
        }
        if ([] !== $params) {
            $message['params'] = $params;
        }

        return json_encode($message, \JSON_THROW_ON_ERROR);
    }

    private function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    private function text(mixed $value, string ...$path): string
    {
        $text = $this->at($value, ...$path);
        self::assertIsString($text);

        return $text;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function context(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
