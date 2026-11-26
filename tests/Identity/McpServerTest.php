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
        'activities' => ['foresi_archive_activity_type', 'foresi_complete_activity', 'foresi_create_activity', 'foresi_create_activity_type', 'foresi_get_activity', 'foresi_list_activities', 'foresi_list_activity_types', 'foresi_list_planned_activities', 'foresi_restore_activity', 'foresi_trash_activity', 'foresi_update_activity', 'foresi_update_activity_type'],
        'calendar' => ['foresi_create_calendar_feed', 'foresi_list_calendar_feeds', 'foresi_rename_calendar_feed', 'foresi_revoke_calendar_feed'],
        'cash-flow' => ['foresi_cash_flow_forecast', 'foresi_change_planned_item', 'foresi_clear_expected_date', 'foresi_create_planned_item', 'foresi_delete_planned_item', 'foresi_end_planned_item', 'foresi_list_pairings', 'foresi_list_planned_items', 'foresi_pair_document', 'foresi_record_balance_checkpoint', 'foresi_set_expected_date', 'foresi_set_minimum_balance', 'foresi_skip_planned_occurrence', 'foresi_unpair_document'],
        'catalog' => ['foresi_archive_product', 'foresi_create_product', 'foresi_get_product', 'foresi_restore_product', 'foresi_search_products', 'foresi_update_product'],
        'documents' => ['foresi_add_document_signer', 'foresi_archive_document_template', 'foresi_archive_document_type', 'foresi_attach_signed_document_copy', 'foresi_cancel_document', 'foresi_create_document', 'foresi_create_document_template', 'foresi_create_document_type', 'foresi_generate_document', 'foresi_generate_document_again', 'foresi_get_document', 'foresi_get_document_template', 'foresi_list_document_templates', 'foresi_list_document_types', 'foresi_list_documents', 'foresi_mark_document_sent', 'foresi_mark_document_signer_declined', 'foresi_mark_document_signer_signed', 'foresi_remove_document_signer', 'foresi_restore_document', 'foresi_save_document_template_version', 'foresi_trash_document', 'foresi_update_document', 'foresi_update_document_signer', 'foresi_update_document_type'],
        'events' => ['foresi_list_event_types', 'foresi_list_events'],
        'exchange-rate' => ['foresi_get_exchange_rate_source', 'foresi_set_exchange_rate_source'],
        'identity' => ['foresi_whoami'],
        'inbox' => ['foresi_create_contact_from_inbox_item', 'foresi_discard_inbox_item', 'foresi_get_inbox_item', 'foresi_link_inbox_item', 'foresi_list_inbox_items', 'foresi_mark_inbox_item_done', 'foresi_match_inbox_item', 'foresi_match_inbox_item_again', 'foresi_reopen_inbox_item', 'foresi_restore_inbox_item', 'foresi_trash_inbox_item'],
        'invoicing' => ['foresi_create_invoice', 'foresi_create_recurring_invoice', 'foresi_end_recurring_invoice', 'foresi_get_invoice', 'foresi_get_payment', 'foresi_get_recurring_invoice', 'foresi_list_recurring_invoices', 'foresi_mark_invoice_sent', 'foresi_pause_recurring_invoice', 'foresi_preview_recurring_invoice', 'foresi_restore_invoice', 'foresi_resume_recurring_invoice', 'foresi_search_invoices', 'foresi_search_payments', 'foresi_trash_invoice', 'foresi_update_invoice', 'foresi_update_recurring_invoice'],
        'mail' => ['foresi_create_mail_address', 'foresi_list_mail_addresses', 'foresi_rename_mail_address', 'foresi_replace_mail_address', 'foresi_revoke_mail_address'],
        'margin' => ['foresi_assign_received_document', 'foresi_client_margin', 'foresi_client_margin_cell', 'foresi_get_document_allocation', 'foresi_get_planned_item_allocation', 'foresi_get_supplier_default_recipient', 'foresi_list_unrebilled_costs', 'foresi_pair_rebill_line', 'foresi_set_line_allocation', 'foresi_set_planned_item_allocation', 'foresi_set_rebill_link', 'foresi_set_supplier_default_recipient', 'foresi_unpair_rebill_line'],
        'party' => ['foresi_add_firm_vat_status', 'foresi_archive_contact', 'foresi_create_contact', 'foresi_get_contact', 'foresi_get_firm', 'foresi_link_contact_person', 'foresi_remove_firm_vat_status', 'foresi_restore_contact', 'foresi_search_contacts', 'foresi_unlink_contact_person', 'foresi_update_contact'],
        'purchasing' => ['foresi_add_received_document_payment', 'foresi_get_received_document', 'foresi_list_received_document_payments', 'foresi_mark_received_document_paid', 'foresi_read_received_document_again', 'foresi_record_received_document', 'foresi_remove_received_document_payment', 'foresi_search_received_documents', 'foresi_set_received_document_lines', 'foresi_trash_received_document', 'foresi_upload_received_document'],
        'sales' => ['foresi_add_deal_line', 'foresi_add_deal_link', 'foresi_archive_deal_stage', 'foresi_archive_lost_reason', 'foresi_change_deal_stage', 'foresi_create_billing_entry', 'foresi_create_deal', 'foresi_create_deal_stage', 'foresi_create_follow_up_deal', 'foresi_create_lost_reason', 'foresi_delete_billing_entry', 'foresi_get_deal', 'foresi_link_billing_entry', 'foresi_list_deal_stages', 'foresi_lose_deal', 'foresi_remove_deal_line', 'foresi_remove_deal_link', 'foresi_reopen_deal', 'foresi_reorder_deal_lines', 'foresi_reorder_deal_stages', 'foresi_restore_deal', 'foresi_search_deals', 'foresi_trash_deal', 'foresi_unlink_billing_entry', 'foresi_update_billing_entry', 'foresi_update_deal', 'foresi_update_deal_line', 'foresi_update_deal_stage', 'foresi_update_lost_reason', 'foresi_win_deal'],
        'shared' => ['foresi_create_view', 'foresi_delete_view', 'foresi_get_view', 'foresi_list_lists', 'foresi_list_modules', 'foresi_list_views', 'foresi_restore_default_views', 'foresi_update_view'],
        'taxes-and-contributions' => ['foresi_add_tax_status', 'foresi_get_tax_profile', 'foresi_remove_tax_status', 'foresi_set_tax_authority_account', 'foresi_set_tax_year', 'foresi_tax_amounts', 'foresi_tax_estimate'],
        'vat' => ['foresi_assign_vat_payment', 'foresi_clear_vat_return_amount', 'foresi_get_vat_period', 'foresi_reopen_vat_period', 'foresi_reset_vat_payment', 'foresi_set_vat_return_amount', 'foresi_set_vat_settings', 'foresi_settle_vat_period', 'foresi_vat_periods'],
        'work' => ['foresi_add_project_budget_change', 'foresi_add_task_comment', 'foresi_archive_project', 'foresi_create_project', 'foresi_create_task', 'foresi_get_project', 'foresi_get_project_budget', 'foresi_get_task', 'foresi_list_labels', 'foresi_list_project_people', 'foresi_list_task_comments', 'foresi_log_time', 'foresi_remove_project_budget_change', 'foresi_restore_project', 'foresi_restore_task', 'foresi_search_projects', 'foresi_search_tasks', 'foresi_search_time_entries', 'foresi_set_project_budget_counting', 'foresi_start_timer', 'foresi_stop_timer', 'foresi_trash_task', 'foresi_trash_task_comment', 'foresi_update_project', 'foresi_update_task', 'foresi_update_task_comment'],
        'webhooks' => ['foresi_create_webhook_endpoint', 'foresi_delete_webhook_endpoint', 'foresi_list_webhook_deliveries', 'foresi_list_webhook_endpoints', 'foresi_redeliver_webhook', 'foresi_rotate_webhook_secret', 'foresi_update_webhook_endpoint'],
    ];

    /** The tools that change data, with whether they destroy: every other tool only reads. */
    private const array WRITE_TOOLS = [
        'foresi_add_deal_line' => false,
        'foresi_add_deal_link' => false,
        'foresi_add_document_signer' => false,
        'foresi_add_firm_vat_status' => false,
        'foresi_add_received_document_payment' => false,
        'foresi_add_project_budget_change' => false,
        'foresi_add_task_comment' => false,
        'foresi_add_tax_status' => false,
        'foresi_archive_activity_type' => true,
        'foresi_archive_contact' => false,
        'foresi_archive_deal_stage' => false,
        'foresi_archive_document_template' => false,
        'foresi_archive_document_type' => false,
        'foresi_archive_lost_reason' => false,
        'foresi_archive_product' => false,
        'foresi_archive_project' => false,
        'foresi_assign_received_document' => false,
        'foresi_assign_vat_payment' => false,
        'foresi_attach_signed_document_copy' => false,
        'foresi_cancel_document' => true,
        'foresi_change_deal_stage' => false,
        'foresi_change_planned_item' => false,
        'foresi_clear_expected_date' => false,
        'foresi_clear_vat_return_amount' => false,
        'foresi_complete_activity' => false,
        'foresi_create_activity' => false,
        'foresi_create_activity_type' => false,
        'foresi_create_calendar_feed' => false,
        'foresi_create_contact' => false,
        'foresi_create_contact_from_inbox_item' => false,
        'foresi_create_deal' => false,
        'foresi_create_deal_stage' => false,
        'foresi_create_mail_address' => false,
        'foresi_create_document' => false,
        'foresi_create_document_template' => false,
        'foresi_create_document_type' => false,
        'foresi_create_follow_up_deal' => false,
        'foresi_create_invoice' => false,
        'foresi_create_lost_reason' => false,
        'foresi_create_planned_item' => false,
        'foresi_create_product' => false,
        'foresi_create_project' => false,
        'foresi_create_recurring_invoice' => false,
        'foresi_create_task' => false,
        'foresi_create_view' => false,
        'foresi_create_webhook_endpoint' => false,
        'foresi_delete_planned_item' => true,
        'foresi_delete_view' => true,
        'foresi_delete_webhook_endpoint' => true,
        'foresi_discard_inbox_item' => false,
        'foresi_end_planned_item' => false,
        'foresi_end_recurring_invoice' => true,
        'foresi_generate_document' => false,
        'foresi_generate_document_again' => false,
        'foresi_link_contact_person' => false,
        'foresi_link_inbox_item' => false,
        'foresi_log_time' => false,
        'foresi_lose_deal' => false,
        'foresi_mark_document_sent' => false,
        'foresi_mark_document_signer_declined' => true,
        'foresi_mark_document_signer_signed' => false,
        'foresi_mark_inbox_item_done' => false,
        'foresi_mark_invoice_sent' => true,
        'foresi_mark_received_document_paid' => false,
        'foresi_match_inbox_item' => false,
        'foresi_match_inbox_item_again' => false,
        'foresi_pair_rebill_line' => false,
        'foresi_pause_recurring_invoice' => false,
        'foresi_read_received_document_again' => false,
        'foresi_record_balance_checkpoint' => false,
        'foresi_record_received_document' => false,
        'foresi_redeliver_webhook' => false,
        'foresi_remove_deal_line' => true,
        'foresi_remove_deal_link' => false,
        'foresi_remove_document_signer' => true,
        'foresi_remove_firm_vat_status' => true,
        'foresi_remove_project_budget_change' => true,
        'foresi_remove_received_document_payment' => true,
        'foresi_remove_tax_status' => true,
        'foresi_rename_calendar_feed' => false,
        'foresi_rename_mail_address' => false,
        'foresi_reopen_deal' => false,
        'foresi_reopen_inbox_item' => false,
        'foresi_reopen_vat_period' => false,
        'foresi_reorder_deal_lines' => false,
        'foresi_reorder_deal_stages' => false,
        'foresi_replace_mail_address' => false,
        'foresi_reset_vat_payment' => false,
        'foresi_pair_document' => false,
        'foresi_restore_activity' => false,
        'foresi_restore_contact' => false,
        'foresi_restore_deal' => false,
        'foresi_restore_document' => false,
        'foresi_restore_default_views' => false,
        'foresi_restore_inbox_item' => false,
        'foresi_restore_invoice' => false,
        'foresi_restore_product' => false,
        'foresi_restore_project' => false,
        'foresi_restore_task' => false,
        'foresi_resume_recurring_invoice' => false,
        'foresi_revoke_calendar_feed' => true,
        'foresi_revoke_mail_address' => true,
        'foresi_rotate_webhook_secret' => true,
        'foresi_save_document_template_version' => false,
        'foresi_set_exchange_rate_source' => false,
        'foresi_set_expected_date' => false,
        'foresi_set_line_allocation' => false,
        'foresi_set_minimum_balance' => false,
        'foresi_set_planned_item_allocation' => false,
        'foresi_set_project_budget_counting' => false,
        'foresi_set_rebill_link' => false,
        'foresi_set_received_document_lines' => false,
        'foresi_set_supplier_default_recipient' => false,
        'foresi_set_tax_authority_account' => false,
        'foresi_set_tax_year' => false,
        'foresi_set_vat_return_amount' => false,
        'foresi_set_vat_settings' => false,
        'foresi_settle_vat_period' => false,
        'foresi_skip_planned_occurrence' => false,
        'foresi_start_timer' => false,
        'foresi_stop_timer' => false,
        'foresi_trash_activity' => true,
        'foresi_trash_deal' => true,
        'foresi_trash_document' => true,
        'foresi_trash_inbox_item' => true,
        'foresi_trash_invoice' => true,
        'foresi_trash_received_document' => true,
        'foresi_trash_task' => true,
        'foresi_trash_task_comment' => true,
        'foresi_unlink_contact_person' => false,
        'foresi_unpair_document' => false,
        'foresi_unpair_rebill_line' => false,
        'foresi_update_activity' => false,
        'foresi_update_activity_type' => false,
        'foresi_update_contact' => false,
        'foresi_update_deal' => false,
        'foresi_update_deal_line' => false,
        'foresi_update_deal_stage' => false,
        'foresi_update_document' => false,
        'foresi_update_document_signer' => false,
        'foresi_update_document_type' => false,
        'foresi_update_invoice' => false,
        'foresi_update_lost_reason' => false,
        'foresi_update_product' => false,
        'foresi_update_project' => false,
        'foresi_update_recurring_invoice' => false,
        'foresi_update_task' => false,
        'foresi_update_task_comment' => false,
        'foresi_update_view' => false,
        'foresi_update_webhook_endpoint' => false,
        'foresi_upload_received_document' => false,
        'foresi_win_deal' => false,
        'foresi_create_billing_entry' => false,
        'foresi_update_billing_entry' => false,
        'foresi_delete_billing_entry' => true,
        'foresi_link_billing_entry' => false,
        'foresi_unlink_billing_entry' => false,
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

        self::assertSame(['foresi_get_contact', 'foresi_get_firm', 'foresi_list_modules', 'foresi_search_contacts', 'foresi_whoami'], $names);
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
            'foresi_add_firm_vat_status',
            'foresi_archive_contact',
            'foresi_create_contact',
            'foresi_get_contact',
            'foresi_get_firm',
            'foresi_link_contact_person',
            'foresi_list_modules',
            'foresi_remove_firm_vat_status',
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
