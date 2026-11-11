<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Domain\ApiScope;
use App\Invoicing\Domain\Invoice;
use App\Shared\Infrastructure\Mcp\ReadToolCall;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The MCP server over Streamable HTTP at /mcp: a client signs in with its
 * personal API token on every request, finds the Foresi tools, all of them
 * read-only, and calls them. No request sets a cookie, and every request spends
 * the budget of the token the REST API spends.
 */
final class McpServerTest extends ApiTestCase
{
    /** Every tool the server offers, by name. */
    private const array TOOLS = [
        'foresi_get_contact',
        'foresi_get_invoice',
        'foresi_get_payment',
        'foresi_list_firms',
        'foresi_search_contacts',
        'foresi_search_invoices',
        'foresi_search_payments',
        'foresi_whoami',
    ];

    private ?string $session = null;
    private string $protocol = '2025-06-18';

    public function testWithoutATokenNothingIsServed(): void
    {
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

        $initialized = $this->rpc('initialize', $this->initialize());
        self::assertSame('foresi', $this->in($initialized, 'result', 'serverInfo', 'name'));
        self::assertSame(self::getContainer()->getParameter('app.version'), $this->in($initialized, 'result', 'serverInfo', 'version'));
        self::assertSame($this->protocol, $this->in($initialized, 'result', 'protocolVersion'));
        self::assertStringContainsString('These tools only read', $this->text($initialized, 'result', 'instructions'));
        self::assertNotNull($this->session);
        $this->notify('notifications/initialized');

        $tools = $this->in($this->rpc('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        sort($names);
        self::assertSame(self::TOOLS, $names);
        foreach ($tools as $tool) {
            self::assertStringStartsWith('foresi_', $this->text($tool, 'name'));
            self::assertSame(['title' => $this->in($tool, 'title'), 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], $this->in($tool, 'annotations'));
        }

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_whoami', 'arguments' => new \stdClass()]), 'result');
        self::assertFalse($this->in($call, 'isError'));
        self::assertSame(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $this->in($call, 'structuredContent', 'untrusted_text_notice'));
        self::assertSame(['id' => $this->user->id(), 'email' => 'testing@example.com'], $this->in($call, 'structuredContent', 'data', 'user'));
        self::assertSame(['id' => $this->tenant->toString(), 'name' => 'Example Organisation'], $this->in($call, 'structuredContent', 'data', 'tenant'));
        self::assertSame('Reporting', $this->in($call, 'structuredContent', 'data', 'token', 'name'));
        self::assertSame(['read'], $this->in($call, 'structuredContent', 'data', 'token', 'scopes'));
        $text = $this->text($call, 'content', '0', 'text');
        self::assertStringStartsWith(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $text);
        self::assertStringContainsString('"email":"testing@example.com"', $text);
    }

    public function testASearchAnswersTheRestDataMarkedAsData(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice('2026-11-02', '2026-11-30'));
        $this->get('/api/v1/invoices');
        $rest = $this->at('data');
        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_search_invoices', 'arguments' => ['firm_id' => $this->firmId, 'limit' => 10]]), 'result');

        self::assertFalse($this->in($call, 'isError'));
        self::assertSame(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $this->in($call, 'structuredContent', 'untrusted_text_notice'));
        self::assertSame(['items' => $rest, 'next_cursor' => null], $this->in($call, 'structuredContent', 'data'));
        self::assertSame($invoice->id(), $this->in($call, 'structuredContent', 'data', 'items', '0', 'id'));
        $text = $this->text($call, 'content', '0', 'text');
        self::assertStringStartsWith(ReadToolCall::UNTRUSTED_TEXT_NOTICE."\n\n1 document, newest first; no more match.\n\n", $text);
        self::assertStringContainsString('"name":"Example Customer a.s."', $text);
    }

    public function testAnUnknownIdIsAnErrorResultTheModelCanReadAndABadDayAnInvalidCall(): void
    {
        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_get_invoice', 'arguments' => ['id' => '01963c5e-0000-7000-8000-000000000001']]), 'result');
        self::assertTrue($this->in($call, 'isError'));
        self::assertSame('not_found', $this->in($call, 'structuredContent', 'error', 'code'));

        // The SDK checks the arguments against the schema before the tool runs.
        $this->post($this->message('tools/call', ['name' => 'foresi_search_invoices', 'arguments' => ['issued_from' => '1. 11. 2026']], 1));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(-32602, $this->in($body, 'error', 'code'));
    }

    public function testOverTheBudgetOfTheTokenTheServerAnswersTooManyRequests(): void
    {
        $this->get('/api/v1/me');
        $tokenId = $this->at('token', 'id');
        self::assertIsString($tokenId);
        self::assertTrue(self::getContainer()->get('test.api_token_limiter')->create($tokenId)->consume(599)->isAccepted());

        $this->post($this->message('initialize', $this->initialize(), 1));

        $this->assertProblem(429, 'rate_limited');
        self::assertResponseHeaderSame('RateLimit-Remaining', '0');
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testATokenWithoutTheReadScopeGetsAnErrorResult(): void
    {
        $this->secret = $this->issued([ApiScope::Write]);

        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');
        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_whoami', 'arguments' => new \stdClass()]), 'result');

        self::assertTrue($this->in($call, 'isError'));
        self::assertSame('insufficient_scope', $this->in($call, 'structuredContent', 'error', 'code'));
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

    private function in(mixed $value, string ...$path): mixed
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
        $text = $this->in($value, ...$path);
        self::assertIsString($text);

        return $text;
    }
}
