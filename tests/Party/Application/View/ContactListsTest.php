<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application\View;

use Foresi\Party\Application\View\ContactLists;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\View\Placement;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The address book's list definition (saved views D3, D12): its fields, derived from its data
 * source (DS1), and the tabs it had.
 */
final class ContactListsTest extends KernelTestCase
{
    public function testItShipsTheTabsItHadInTheirOrderWithTheirCounts(): void
    {
        $lists = $this->lists();
        $list = $lists->list();

        self::assertSame([$list], iterator_to_array($lists->lists()));
        self::assertSame('party.contacts', $list->key);
        self::assertSame(
            [
                ['all', 'party.view.all', [], 0],
                ['customers', 'party.view.customers', [['field' => 'roles', 'op' => 'has_any_of', 'value' => ['customer']]], 1],
                ['suppliers', 'party.view.suppliers', [['field' => 'roles', 'op' => 'has_any_of', 'value' => ['supplier']]], 2],
                ['archived', 'party.view.archived', [['field' => 'archived', 'op' => 'is', 'value' => true]], 3],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertTrue($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testEveryAttributeWithAnExpressionIsAFieldBesideTheTwoTheModuleCompiles(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame([
            'archived', 'unreliable_payer', 'name', 'kind', 'roles', 'registration_number', 'vat_id', 'email', 'phone', 'mobile', 'website',
            'registration_country', 'billing_city', 'billing_country', 'default_due_days', 'currency', 'payment_method', 'language',
            'reverse_charge', 'reminders', 'hourly_rate', 'vies_result', 'archived_at',
            // Its relations to many (DS6), each with the name of a related record: its own and other modules'.
            'contact_persons', 'contact_persons.name', 'invoices', 'invoices.name', 'projects', 'projects.name',
        ], array_keys($fields));
        self::assertSame(FieldType::Relation, $fields['invoices']->type);
        self::assertSame(['is_not_empty', 'is_empty'], $fields['invoices']->operators);
        self::assertSame('invoice.relation.invoices', $fields['invoices.name']->group);
        self::assertSame('ci.number', $fields['invoices.name']->expression);
        self::assertSame(FieldType::Boolean, $fields['unreliable_payer']->type);
        self::assertSame('party.field.unreliablePayerResult', $fields['unreliable_payer']->label);
        self::assertSame(FieldType::Text, $fields['email']->type);
        self::assertSame(FieldType::MultiEnum, $fields['roles']->type);
        self::assertSame(['role'], $fields['roles']->aliases);
        self::assertSame(['customer' => 'party.role.customer', 'supplier' => 'party.role.supplier', 'partner' => 'party.role.partner', 'authority' => 'party.role.authority'], $fields['roles']->optionLabels);
        self::assertSame(['person' => 'party.kind.person', 'sole_trader' => 'party.kind.sole_trader', 'company' => 'party.kind.company'], $fields['kind']->optionLabels);
        self::assertSame(100, $fields['hourly_rate']->scale);
        self::assertSame(['contains', 'does_not_contain', 'is', 'is_not', 'is_empty', 'is_not_empty'], $fields['name']->operators);
    }

    public function testAViewKeptOnTheRoleReadsAsTheSetOfRoles(): void
    {
        $list = $this->lists()->list();

        self::assertEquals(ViewFilter::all(new Condition('roles', 'has_any_of', ['customer'])), ViewFilter::parse(['conditions' => [['field' => 'role', 'op' => 'is', 'value' => 'customer']]], $list));
        self::assertEquals(ViewFilter::all(new Condition('roles', 'has_none_of', ['partner', 'authority'])), ViewFilter::parse(['conditions' => [['field' => 'role', 'op' => 'is_none_of', 'value' => ['partner', 'authority']]]], $list));
    }

    private function lists(): ContactLists
    {
        return self::getContainer()->get(ContactLists::class);
    }
}
