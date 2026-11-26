<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure\Security;

use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\RouterInterface;

/**
 * A guest reaches only the sign-in, their account, the public links
 * and what is shared with them: every other route of the application, those of
 * modules yet to come included, answers 403. The settings of a project, its
 * trash, sharing, new projects and the labels stay the staff's.
 */
final class GuestAccessTest extends WebTestCase
{
    /**
     * The routes a guest may use, and the firewall's own.
     */
    private const array OPEN = [
        // The sign-in, its code step and signing out.
        'app_login', '2fa_login', '2fa_login_check', 'app_logout',
        // Their account, and their profile: every guest has one (name, photo), and the person's photo is shown wherever they appear.
        'app_account_profile', 'app_account_profile_edit', 'app_account_profile_photo', 'app_account_profile_photo_remove', 'app_user_photo', 'api_v1_operation_identity.profile.update',
        'app_account_language', 'app_two_factor_setup', 'app_two_factor_recovery_codes',
        // Machines: the uptime monitor, the bank mail relay and calendar apps fetching a feed, on firewalls without a session.
        'app_health', 'app_inbound_mail', 'app_calendar_feed',
        // OAuth for MCP clients (ADR 0036): metadata, registration and the token endpoint hold no data and
        // take no session; a guest connects their own agent on the consent screen and manages it
        // on Connected apps (ADR 0041).
        'oauth_resource_metadata', 'oauth_resource_metadata_root', 'oauth_server_metadata', 'oauth_register', 'oauth_token',
        'oauth_authorize', 'app_connected_apps', 'app_connected_app_disconnect',
        // The setup guide for AI agents and the llms.txt: public, only this installation's addresses.
        'app_mcp_setup', 'app_llms_txt',
        // The API and MCP, whose firewalls only an API token signs in to: a guest has no personal token,
        // and a guest's OAuth connection holds only the scopes a guest may hold (ADR 0041).
        'api_v1_me', 'api_v1_invoices', 'api_v1_invoice', 'api_v1_contacts', 'api_v1_contact', 'api_v1_firm', 'api_v1_payments', 'api_v1_payment', 'api_v1_events',
        'api_v1_webhook_endpoints', 'api_v1_webhook_endpoint_create', 'api_v1_webhook_endpoint', 'api_v1_webhook_endpoint_update', 'api_v1_webhook_endpoint_delete',
        'api_v1_webhook_endpoint_rotate', 'api_v1_webhook_endpoint_secret', 'api_v1_webhook_endpoint_ping',
        'api_v1_webhook_deliveries', 'api_v1_webhook_delivery', 'api_v1_webhook_delivery_redeliver', '_mcp_endpoint_default',
        'api_v1_operation_contacts.create', 'api_v1_operation_contacts.update', 'api_v1_operation_contacts.archive', 'api_v1_operation_contacts.restore',
        'api_v1_operation_contacts.link_person', 'api_v1_operation_contacts.unlink_person',
        'api_v1_operation_party.firm.vat_status.add', 'api_v1_operation_party.firm.vat_status.remove',
        'api_v1_cash_flow_forecast', 'api_v1_cash_flow_planned_items', 'api_v1_cash_flow_planned_item', 'api_v1_cash_flow_pairings',
        'api_v1_operation_cashflow.pairings.pair', 'api_v1_operation_cashflow.pairings.unpair',
        'api_v1_operation_cashflow.checkpoints.record', 'api_v1_operation_cashflow.expected_dates.clear', 'api_v1_operation_cashflow.expected_dates.set',
        'api_v1_operation_cashflow.minimum_balance.set', 'api_v1_operation_cashflow.planned_items.change', 'api_v1_operation_cashflow.planned_items.create',
        'api_v1_operation_cashflow.planned_items.delete', 'api_v1_operation_cashflow.planned_items.end', 'api_v1_operation_cashflow.planned_items.skip',
        'api_v1_catalog_products', 'api_v1_catalog_product', 'api_v1_operation_catalog.products.create', 'api_v1_operation_catalog.products.update',
        'api_v1_operation_catalog.products.archive', 'api_v1_operation_catalog.products.restore',
        'api_v1_sales_deals', 'api_v1_sales_deal', 'api_v1_sales_stages', 'api_v1_sales_lost_reasons', 'api_v1_operation_sales.deal_lines.add', 'api_v1_operation_sales.deal_links.add', 'api_v1_operation_sales.stages.archive', 'api_v1_operation_sales.lost_reasons.archive', 'api_v1_operation_sales.deals.change_stage', 'api_v1_operation_sales.deals.create', 'api_v1_operation_sales.stages.create', 'api_v1_operation_sales.deals.create_follow_up', 'api_v1_operation_sales.lost_reasons.create', 'api_v1_operation_sales.deals.lose', 'api_v1_operation_sales.deal_lines.remove', 'api_v1_operation_sales.deal_links.remove', 'api_v1_operation_sales.deals.reopen', 'api_v1_operation_sales.deal_lines.reorder', 'api_v1_operation_sales.stages.reorder', 'api_v1_operation_sales.deals.restore', 'api_v1_operation_sales.deals.trash', 'api_v1_operation_sales.deals.update', 'api_v1_operation_sales.deal_lines.update', 'api_v1_operation_sales.stages.update', 'api_v1_operation_sales.lost_reasons.update', 'api_v1_operation_sales.deals.win',
        'api_v1_received_documents', 'api_v1_received_document', 'api_v1_received_document_payments',
        'api_v1_operation_received_documents.record', 'api_v1_operation_received_documents.trash',
        'api_v1_operation_received_documents.add_payment', 'api_v1_operation_received_documents.mark_paid',
        'api_v1_operation_received_documents.remove_payment', 'api_v1_operation_received_documents.lines.set',
        'api_v1_operation_received_documents.read_again', 'api_v1_operation_received_documents.upload',
        'api_v1_calendar_feeds', 'api_v1_operation_calendar.feeds.create', 'api_v1_operation_calendar.feeds.rename', 'api_v1_operation_calendar.feeds.revoke',
        'api_v1_inbox_items', 'api_v1_inbox_item', 'api_v1_operation_inbox.items.match', 'api_v1_operation_inbox.items.match_again', 'api_v1_operation_inbox.items.create_contact',
        'api_v1_operation_inbox.items.done', 'api_v1_operation_inbox.items.discard', 'api_v1_operation_inbox.items.reopen', 'api_v1_operation_inbox.items.link',
        'api_v1_operation_inbox.items.trash', 'api_v1_operation_inbox.items.restore',
        // The inbox actions of the module only the tests have (tests/Inbox/Fixtures/Action).
        'api_v1_operation_inbox.actions.fixture_note', 'api_v1_operation_inbox.actions.fixture_contact_note',
        // The inbox actions of Sales and Activities.
        'api_v1_operation_inbox.actions.create_deal', 'api_v1_operation_inbox.actions.log_activity',
        // The inbox actions of Work.
        'api_v1_operation_inbox.actions.create_task', 'api_v1_operation_inbox.actions.log_time', 'api_v1_operation_inbox.actions.add_to_budget',
        'api_v1_mail_addresses', 'api_v1_operation_mail.addresses.create', 'api_v1_operation_mail.addresses.rename', 'api_v1_operation_mail.addresses.revoke', 'api_v1_operation_mail.addresses.replace',
        'documents_api_documents', 'documents_api_document', 'documents_api_document_file', 'documents_api_types', 'documents_api_templates', 'documents_api_template',
        'api_v1_operation_documents.types.create', 'api_v1_operation_documents.types.update', 'api_v1_operation_documents.types.archive', 'api_v1_operation_documents.templates.create', 'api_v1_operation_documents.templates.save_version', 'api_v1_operation_documents.templates.archive', 'api_v1_operation_documents.create', 'api_v1_operation_documents.update', 'api_v1_operation_documents.generate', 'api_v1_operation_documents.mark_sent', 'api_v1_operation_documents.attach_signed_copy', 'api_v1_operation_documents.cancel', 'api_v1_operation_documents.trash', 'api_v1_operation_documents.restore', 'api_v1_operation_documents.generate_again', 'api_v1_operation_documents.signers.add', 'api_v1_operation_documents.signers.update', 'api_v1_operation_documents.signers.remove', 'api_v1_operation_documents.signers.mark_signed', 'api_v1_operation_documents.signers.mark_declined',
        'activities_api_activities', 'activities_api_planned', 'activities_api_activity', 'activities_api_types',
        'api_v1_operation_activities.create', 'api_v1_operation_activities.update', 'api_v1_operation_activities.complete', 'api_v1_operation_activities.trash', 'api_v1_operation_activities.restore', 'api_v1_operation_activities.types.create', 'api_v1_operation_activities.types.update', 'api_v1_operation_activities.types.archive',
        'api_v1_taxes_profile', 'api_v1_taxes_amounts', 'api_v1_taxes_estimate', 'api_v1_operation_taxes.profile.status.add', 'api_v1_operation_taxes.profile.status.remove',
        'api_v1_operation_taxes.profile.year.set', 'api_v1_operation_taxes.profile.authority.set',
        'api_v1_margin_document', 'api_v1_operation_margin.documents.assign', 'api_v1_operation_margin.line_allocations.set',
        'api_v1_margin_planned_item_allocation', 'api_v1_margin_supplier_default', 'api_v1_margin_table', 'api_v1_margin_cell', 'api_v1_operation_margin.planned_allocations.set', 'api_v1_operation_margin.supplier_defaults.set', 'api_v1_margin_unrebilled', 'api_v1_operation_margin.rebill_links.set', 'api_v1_operation_margin.rebill_pairings.pair', 'api_v1_operation_margin.rebill_pairings.unpair',
        'api_v1_vat_periods', 'api_v1_vat_period', 'api_v1_vat_settings', 'api_v1_operation_vat.settings.set',
        'api_v1_exchange_rate_source', 'api_v1_operation_exchange-rate.source.set',
        'api_v1_operation_vat.period.return_amount.set', 'api_v1_operation_vat.period.return_amount.clear', 'api_v1_operation_vat.period.settle', 'api_v1_operation_vat.period.reopen', 'api_v1_operation_vat.payment.assign', 'api_v1_operation_vat.payment.reset',
        'api_v1_operation_invoices.create', 'api_v1_operation_invoices.update', 'api_v1_operation_invoices.trash', 'api_v1_operation_invoices.restore', 'api_v1_operation_invoices.mark_sent',
        'api_v1_recurring_invoices', 'api_v1_recurring_invoice', 'api_v1_recurring_invoice_preview',
        'api_v1_operation_recurring-invoices.create', 'api_v1_operation_recurring-invoices.update', 'api_v1_operation_recurring-invoices.pause', 'api_v1_operation_recurring-invoices.resume', 'api_v1_operation_recurring-invoices.end',
        'api_v1_lists', 'api_v1_views', 'api_v1_view', 'api_v1_view_count',
        'api_v1_projects', 'api_v1_project', 'api_v1_project_people', 'api_v1_project_budget', 'api_v1_tasks', 'api_v1_task', 'api_v1_task_comments', 'api_v1_labels', 'api_v1_time_entries',
        'api_v1_operation_tasks.create', 'api_v1_operation_tasks.update', 'api_v1_operation_tasks.trash', 'api_v1_operation_tasks.restore', 'api_v1_operation_tasks.comment',
        'api_v1_operation_task_comments.update', 'api_v1_operation_task_comments.trash',
        'api_v1_operation_projects.create', 'api_v1_operation_projects.update', 'api_v1_operation_projects.archive', 'api_v1_operation_projects.restore',
        'api_v1_operation_work.project_budget.add_change', 'api_v1_operation_work.project_budget.remove_change', 'api_v1_operation_work.project_budget.set_counting',
        'api_v1_operation_time_entries.log', 'api_v1_operation_time_entries.start_timer', 'api_v1_operation_time_entries.stop_timer',
        'api_v1_operation_views.create', 'api_v1_operation_views.update', 'api_v1_operation_views.delete', 'api_v1_operation_views.restore_defaults',
        // The OpenAPI document, which describes the API and holds no data.
        'api_v1_openapi',
        // The public links to sent documents.
        'app_document_link', 'app_document_link_pdf', 'app_document_link_open', 'app_document_link_download', 'app_document_link_other',
        // The link of an invitation, which may well be for a guest.
        'app_invitation',
        // The start page, which sends them on to what is shared with them.
        'app_dashboard', 'app_shared_with_me',
        // The work shared with them: the projects index sends them to it, the project, its board and
        // the task are what they see of them, the rest what they do on a task they see. Each answers
        // 404 for what is not shared with them (tests/Work/Infrastructure/Controller/GuestWorkTest.php).
        'app_projects', 'app_project', 'app_project_board', 'app_project_people', 'app_project_task_new', 'app_task_move', 'app_my_tasks',
        // The list of all tasks holds only the tasks they see, a new task goes only where they may add one.
        'app_tasks', 'app_task_list_projects', 'app_task_list_labels', 'app_task_new',
        'app_task', 'app_task_comment_add', 'app_task_comment_edit', 'app_task_comment_delete', 'app_task_comment_restore', 'app_task_mention_candidates',
        'app_task_title', 'app_task_description', 'app_task_status', 'app_task_responsible', 'app_task_due', 'app_task_priority', 'app_task_custom_field',
        'app_task_labels', 'app_task_section', 'app_task_watch', 'app_task_unwatch', 'app_task_watchers', 'app_task_subtask_new',
        'app_markdown_preview', 'app_attachment', 'app_attachment_upload', 'app_attachment_delete', 'app_attachment_restore',
        // Their own inbox, which holds only the tasks they see.
        'app_notifications', 'app_notifications_read_all', 'app_notification_done', 'app_notification_undone',
        // Their own e-mail settings, and the signed link in an e-mail that stops one kind of them.
        'app_notification_settings', 'app_notification_unsubscribe', 'app_notification_unsubscribe_legacy',
        // No live components exist: an unknown one is not found before any controller runs.
        'ux_live_component',
    ];

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staffRoutes(): iterable
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all();
        self::ensureKernelShutdown();
        foreach (array_keys($routes) as $name) {
            if (!\in_array($name, self::OPEN, true)) {
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('staffRoutes')]
    public function testAGuestIsDenied(string $name): void
    {
        $this->signIn(Role::Guest);
        [$method, $url] = $this->request($name);

        $this->client->request($method, $url);

        self::assertResponseStatusCodeSame(403, $method.' '.$url);
    }

    public function testTheStaffKeepTheirDashboardAndTheAddressBook(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('#sidebar [data-nav-section] li > a')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertContains('/', $links);
        self::assertContains('/contacts', $links);
        self::assertNotContains('/shared', $links);
    }

    public function testAGuestKeepsTheirAccount(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
    }

    private function signIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    /**
     * The first method of the route and a URL with a value that fits each of its parameters.
     *
     * @return array{string, string}
     */
    private function request(string $name): array
    {
        $route = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get($name);
        self::assertNotNull($route);
        $method = $route->getMethods()[0] ?? 'GET';
        $parameters = [];
        foreach ($route->compile()->getPathVariables() as $variable) {
            self::assertIsString($variable);
            // A parameter of a few words, such as the kind of a shared item, takes the first; a number, such as
            // the position of an attachment, takes 1.
            $requirement = $route->getRequirement($variable) ?? '';
            $parameters[$variable] = match (true) {
                1 === preg_match('/^[a-z_]+(\|[a-z_]+)*$/', $requirement) => explode('|', $requirement)[0],
                str_starts_with($requirement, '\\d') => '1',
                default => Id::generate(),
            };
        }
        $router = self::getContainer()->get(RouterInterface::class);
        $words = array_filter($parameters, static fn (string $value): bool => 1 === preg_match('/^[a-z_]+$/', $value));
        foreach ([$parameters, $words + array_map(static fn (): string => '1', $parameters), $words + array_map(static fn (): string => 'x', $parameters)] as $candidate) {
            try {
                return [$method, $router->generate($name, $candidate)];
            } catch (InvalidParameterException) {
                continue;
            }
        }
        self::fail(sprintf('No URL fits the route "%s".', $name));
    }
}
