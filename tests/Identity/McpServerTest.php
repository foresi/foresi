<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Infrastructure\Mcp\ReadToolCall;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The MCP server over Streamable HTTP at /mcp: a client signs in with its
 * personal API token on every request, finds the Foresi tools, each with its
 * hints (read-only or not, destructive or not), and calls them. No request sets a cookie, and every request spends
 * the budget of the token the REST API spends.
 */
final class McpServerTest extends ApiTestCase
{
    /** The tools of each module: the server lists those of the installed ones. */
    private const array TOOLS = [
        'cash-flow' => ['foresi_cash_flow_forecast', 'foresi_change_planned_item', 'foresi_clear_expected_date', 'foresi_create_planned_item', 'foresi_delete_planned_item', 'foresi_end_planned_item', 'foresi_list_planned_items', 'foresi_record_balance_checkpoint', 'foresi_set_expected_date', 'foresi_set_minimum_balance', 'foresi_skip_planned_occurrence'],
        'events' => ['foresi_list_event_types', 'foresi_list_events'],
        'identity' => ['foresi_whoami'],
        'invoicing' => ['foresi_create_invoice', 'foresi_get_invoice', 'foresi_get_payment', 'foresi_restore_invoice', 'foresi_search_invoices', 'foresi_search_payments', 'foresi_trash_invoice', 'foresi_update_invoice'],
        'party' => ['foresi_archive_contact', 'foresi_create_contact', 'foresi_get_contact', 'foresi_link_contact_person', 'foresi_list_firms', 'foresi_restore_contact', 'foresi_search_contacts', 'foresi_unlink_contact_person', 'foresi_update_contact'],
        'purchasing' => ['foresi_add_received_document_payment', 'foresi_get_received_document', 'foresi_list_received_document_payments', 'foresi_mark_received_document_paid', 'foresi_record_received_document', 'foresi_remove_received_document_payment', 'foresi_search_received_documents', 'foresi_trash_received_document'],
        'shared' => ['foresi_create_view', 'foresi_delete_view', 'foresi_get_view', 'foresi_list_lists', 'foresi_list_modules', 'foresi_list_views', 'foresi_restore_default_views', 'foresi_update_view'],
        'webhooks' => ['foresi_create_webhook_endpoint', 'foresi_delete_webhook_endpoint', 'foresi_list_webhook_deliveries', 'foresi_list_webhook_endpoints', 'foresi_redeliver_webhook', 'foresi_rotate_webhook_secret', 'foresi_update_webhook_endpoint'],
    ];

    /** The tools that change data, with whether they destroy: every other tool only reads. */
    private const array WRITE_TOOLS = [
        'foresi_add_received_document_payment' => false,
        'foresi_archive_contact' => false,
        'foresi_change_planned_item' => false,
        'foresi_clear_expected_date' => false,
        'foresi_create_contact' => false,
        'foresi_create_invoice' => false,
        'foresi_create_planned_item' => false,
        'foresi_create_view' => false,
        'foresi_create_webhook_endpoint' => false,
        'foresi_delete_planned_item' => true,
        'foresi_delete_view' => true,
        'foresi_delete_webhook_endpoint' => true,
        'foresi_end_planned_item' => false,
        'foresi_link_contact_person' => false,
        'foresi_mark_received_document_paid' => false,
        'foresi_record_balance_checkpoint' => false,
        'foresi_record_received_document' => false,
        'foresi_redeliver_webhook' => false,
        'foresi_remove_received_document_payment' => true,
        'foresi_restore_contact' => false,
        'foresi_restore_default_views' => false,
        'foresi_restore_invoice' => false,
        'foresi_rotate_webhook_secret' => true,
        'foresi_set_expected_date' => false,
        'foresi_set_minimum_balance' => false,
        'foresi_skip_planned_occurrence' => false,
        'foresi_trash_invoice' => true,
        'foresi_trash_received_document' => true,
        'foresi_unlink_contact_person' => false,
        'foresi_update_contact' => false,
        'foresi_update_invoice' => false,
        'foresi_update_view' => false,
        'foresi_update_webhook_endpoint' => false,
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
    public function testAClientFindsTheToolsWithTheirHintsAndCallsOne(string $protocol): void
    {
        $this->protocol = $protocol;
        $this->secret = $this->issued([ApiScope::read(), ApiScope::write()]);

        $initialized = $this->rpc('initialize', $this->initialize());
        self::assertSame('foresi', $this->in($initialized, 'result', 'serverInfo', 'name'));
        self::assertSame(self::getContainer()->getParameter('app.version'), $this->in($initialized, 'result', 'serverInfo', 'version'));
        self::assertSame($this->protocol, $this->in($initialized, 'result', 'protocolVersion'));
        self::assertStringContainsString('The read tools only read', $this->text($initialized, 'result', 'instructions'));
        self::assertNotNull($this->session);
        $this->notify('notifications/initialized');

        $tools = $this->in($this->rpc('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        sort($names);
        self::assertSame($this->installedTools(), $names);
        foreach ($tools as $tool) {
            self::assertStringStartsWith('foresi_', $this->text($tool, 'name'));
            $name = $this->text($tool, 'name');
            $expected = \array_key_exists($name, self::WRITE_TOOLS)
                ? ['title' => $this->in($tool, 'title'), 'readOnlyHint' => false, 'destructiveHint' => self::WRITE_TOOLS[$name], 'idempotentHint' => false, 'openWorldHint' => false]
                : ['title' => $this->in($tool, 'title'), 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
            self::assertSame($expected, $this->in($tool, 'annotations'));
        }

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_whoami', 'arguments' => new \stdClass()]), 'result');
        self::assertFalse($this->in($call, 'isError'));
        self::assertSame(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $this->in($call, 'structuredContent', 'untrusted_text_notice'));
        self::assertSame(['id' => $this->user->id(), 'email' => 'testing@example.com'], $this->in($call, 'structuredContent', 'data', 'user'));
        self::assertSame('Reporting', $this->in($call, 'structuredContent', 'data', 'token', 'name'));
        self::assertSame(['read', 'write'], $this->in($call, 'structuredContent', 'data', 'token', 'scopes'));
        $text = $this->text($call, 'content', '0', 'text');
        self::assertStringStartsWith(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $text);
        self::assertStringContainsString('"email":"testing@example.com"', $text);
    }

    public function testASearchAnswersTheRestDataMarkedAsData(): void
    {
        $this->get('/api/v1/contacts');
        $rest = $this->at('data');
        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_search_contacts', 'arguments' => ['limit' => 10]]), 'result');

        self::assertFalse($this->in($call, 'isError'));
        self::assertSame(ReadToolCall::UNTRUSTED_TEXT_NOTICE, $this->in($call, 'structuredContent', 'untrusted_text_notice'));
        self::assertSame(['items' => $rest, 'next_cursor' => null, 'applied_view' => null], $this->in($call, 'structuredContent', 'data'));
        self::assertSame($this->customerId, $this->in($call, 'structuredContent', 'data', 'items', '0', 'id'));
        $text = $this->text($call, 'content', '0', 'text');
        self::assertStringStartsWith(ReadToolCall::UNTRUSTED_TEXT_NOTICE."\n\n1 contact, newest first; no more match.\n\n", $text);
        self::assertStringContainsString('"name":"Example Customer a.s."', $text);
    }

    public function testAnUnknownIdIsAnErrorResultTheModelCanReadAndABadDayAnInvalidCall(): void
    {
        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_get_contact', 'arguments' => ['id' => '01963c5e-0000-7000-8000-000000000001']]), 'result');
        self::assertTrue($this->in($call, 'isError'));
        self::assertSame('not_found', $this->in($call, 'structuredContent', 'error', 'code'));

        // The SDK checks the arguments against the schema before the tool runs.
        $this->post($this->message('tools/call', ['name' => 'foresi_search_contacts', 'arguments' => ['limit' => 'ten']], 1));
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

    public function testATokenListsOnlyTheToolsItsScopesAllow(): void
    {
        $this->secret = $this->issued([ApiScope::from('contacts:read')]);

        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');
        $tools = $this->in($this->rpc('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        sort($names);

        self::assertSame(['foresi_get_contact', 'foresi_list_firms', 'foresi_list_modules', 'foresi_search_contacts', 'foresi_whoami'], $names);
    }

    public function testAContactsWriteTokenListsTheContactToolsAlone(): void
    {
        $this->secret = $this->issued([ApiScope::from('contacts:write')]);

        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');
        $tools = $this->in($this->rpc('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        sort($names);

        // contacts:write grants contacts:read, and nothing of invoices, payments, events or webhooks.
        self::assertSame([
            'foresi_archive_contact',
            'foresi_create_contact',
            'foresi_get_contact',
            'foresi_link_contact_person',
            'foresi_list_firms',
            'foresi_list_modules',
            'foresi_restore_contact',
            'foresi_search_contacts',
            'foresi_unlink_contact_person',
            'foresi_update_contact',
            'foresi_whoami',
        ], $names);
    }

    public function testAToolOutsideTheScopesOfTheTokenGetsAnErrorResult(): void
    {
        $this->secret = $this->issued([ApiScope::from('contacts:read')]);

        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');
        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_list_events', 'arguments' => new \stdClass()]), 'result');

        self::assertTrue($this->in($call, 'isError'));
        self::assertSame('insufficient_scope', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame('The token does not have the "events:read" scope.', $this->in($call, 'structuredContent', 'error', 'message'));
    }

    public function testAWriteToolNeedsTheWriteScope(): void
    {
        $this->rpc('initialize', $this->initialize());
        $this->notify('notifications/initialized');

        $call = $this->in($this->rpc('tools/call', ['name' => 'foresi_delete_webhook_endpoint', 'arguments' => ['endpoint_id' => '01963c5e-0000-7000-8000-000000000001']]), 'result');

        self::assertTrue($this->in($call, 'isError'));
        self::assertSame('insufficient_scope', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame('The token does not have the "webhooks:write" scope.', $this->in($call, 'structuredContent', 'error', 'message'));
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

    /**
     * @return list<string> the tools of the installed modules, sorted
     */
    private function installedTools(): array
    {
        $tools = [];
        foreach (new InstalledModules(\dirname(__DIR__, 2))->graph()->all() as $module) {
            array_push($tools, ...self::TOOLS[$module->id->value] ?? []);
        }
        sort($tools);

        return $tools;
    }
}
