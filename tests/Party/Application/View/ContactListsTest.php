<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application\View;

use Foresi\Party\Application\View\ContactLists;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Domain\View\Placement;
use PHPUnit\Framework\TestCase;

/**
 * The address book's list definition (saved views D3, D12): its fields and the tabs it had.
 */
final class ContactListsTest extends TestCase
{
    public function testItShipsTheTabsItHadInTheirOrderWithTheirCounts(): void
    {
        $list = ContactLists::list();

        self::assertEquals([$list], iterator_to_array((new ContactLists())->lists()));
        self::assertSame('party.contacts', $list->key);
        self::assertSame(
            [
                ['all', 'party.view.all', [], 0],
                ['customers', 'party.view.customers', [['field' => 'role', 'op' => 'is', 'value' => 'customer']], 1],
                ['suppliers', 'party.view.suppliers', [['field' => 'role', 'op' => 'is', 'value' => 'supplier']], 2],
                ['archived', 'party.view.archived', [['field' => 'archived', 'op' => 'is', 'value' => true]], 3],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertTrue($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsNameTheirValues(): void
    {
        $fields = ContactLists::list()->fields;

        self::assertSame(['archived', 'role', 'kind', 'unreliable_payer'], array_keys($fields));
        self::assertSame(['customer' => 'party.role.customer', 'supplier' => 'party.role.supplier', 'partner' => 'party.role.partner', 'authority' => 'party.role.authority'], $fields['role']->optionLabels);
        self::assertSame(['person' => 'party.kind.person', 'sole_trader' => 'party.kind.sole_trader', 'company' => 'party.kind.company'], $fields['kind']->optionLabels);
        self::assertSame('party.field.unreliablePayerResult', $fields['unreliable_payer']->label);
    }
}
