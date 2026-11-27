<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Keeps the old names of the public tags working (ADR 0044). Before module API
 * 1.0 the tags were named app.*; they are foresi.<module>.<name> now, the TAG
 * constant of their interface. A service that still carries an old name gets
 * the new one as well, with the same attributes, unless it has it already, and a deprecation that names
 * the service. Symfony has no way to deprecate a tag itself.
 *
 * Only the tags of interfaces in a Contract namespace are here: the tags of
 * internal interfaces were renamed without an old name. The map is kept as long
 * as the aliases of the classes that moved into Contract, until module API 3.0.
 */
final class DeprecatedTagsPass implements CompilerPassInterface
{
    /**
     * The old name of each public tag and its name since module API 1.0.
     */
    public const array RENAMED = [
        'app.activity_target' => 'foresi.activities.target',
        'app.bank_account_usage' => 'foresi.party.bank_account_usage',
        'app.bank_message_parser' => 'foresi.banking.message_parser',
        'app.calendar_entry_source' => 'foresi.calendar.entry_source',
        'app.cash_flow_actual_source' => 'foresi.cash_flow.actual_source',
        'app.cash_flow_balance_source' => 'foresi.cash_flow.balance_source',
        'app.cash_flow_pairable_documents' => 'foresi.cash_flow.pairable_documents',
        'app.cash_flow_source' => 'foresi.cash_flow.source',
        'app.dashboard_section' => 'foresi.ui.dashboard_section',
        'app.data_source' => 'foresi.ui.data_source',
        'app.document_link_target' => 'foresi.document_templates.link_target',
        'app.document_variable_source' => 'foresi.document_templates.variable_source',
        'app.domain_event_listener' => 'foresi.shared.domain_event_listener',
        'app.exchange_rate_source' => 'foresi.exchange_rate.source',
        'app.guest_home' => 'foresi.identity.guest_home',
        'app.import_preset' => 'foresi.import.preset',
        'app.import_target' => 'foresi.import.target',
        'app.import_target_pages' => 'foresi.import.target_pages',
        'app.invoicing_country' => 'foresi.invoicing.country',
        'app.mail_inbound_purpose' => 'foresi.mail.inbound_purpose',
        'app.margin_cost_source' => 'foresi.margin.cost_source',
        'app.margin_revenue_source' => 'foresi.margin.revenue_source',
        'app.mcp_read_tool' => 'foresi.shared.mcp_read_tool',
        'app.mcp_write_tool' => 'foresi.shared.mcp_write_tool',
        'app.navigation_area' => 'foresi.ui.navigation_area',
        'app.navigation_item' => 'foresi.ui.navigation_item',
        'app.operation_handler' => 'foresi.shared.operation_handler',
        'app.relation_source' => 'foresi.ui.relation_source',
        'app.relation_target' => 'foresi.ui.relation_target',
        'app.scheduled_job' => 'foresi.instance.scheduled_job',
        'app.shell_notice' => 'foresi.ui.shell_notice',
        'app.slot_provider' => 'foresi.ui.slot_provider',
        'app.taxes_actual_source' => 'foresi.taxes_and_contributions.actual_source',
        'app.taxes_forecast_source' => 'foresi.taxes_and_contributions.forecast_source',
        'app.taxes_levy_payment_source' => 'foresi.taxes_and_contributions.levy_payment_source',
        'app.taxes_planned_levy_source' => 'foresi.taxes_and_contributions.planned_levy_source',
        'app.taxes_regime' => 'foresi.taxes_and_contributions.regime',
        'app.timesheet_project_rate_source' => 'foresi.timesheet.project_rate_source',
        'app.vat_country_rules' => 'foresi.vat.country_rules',
        'app.vat_document_source' => 'foresi.vat.document_source',
        'app.vat_payment_source' => 'foresi.vat.payment_source',
        'app.vat_plan_source' => 'foresi.vat.plan_source',
        'app.vat_planned_item_source' => 'foresi.vat.planned_item_source',
        'app.vat_schedule_source' => 'foresi.vat.schedule_source',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::RENAMED as $old => $new) {
            foreach ($container->findTaggedServiceIds($old) as $id => $tags) {
                $definition = $container->getDefinition($id);
                if (!$definition->hasTag($new)) {
                    foreach ($tags as $attributes) {
                        $definition->addTag($new, \is_array($attributes) ? $attributes : []);
                    }
                }
                trigger_deprecation('foresi/shared', '0.11', 'The service "%s" is tagged "%s", which is deprecated since module API 1.0 and removed in 3.0. Tag it "%s" instead, or let its interface tag it by autoconfiguration.', $id, $old, $new);
            }
        }
    }
}
