<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application\View;

use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Tests\Banking\Support\ScreenStores;
use PHPUnit\Framework\TestCase;

final class BankMovementListsTest extends TestCase
{
    public function testItShipsAllAndTheTabsItHadWithTheCountsTheyHad(): void
    {
        $list = BankMovementLists::of([]);

        self::assertSame('banking.movements', $list->key);
        self::assertSame(
            [
                ['all', 'bank.movement.view.all', [], 0, false],
                ['incoming', 'bank.movement.tab.incoming', [['field' => 'direction', 'op' => 'is', 'value' => 'incoming']], 1, false],
                ['outgoing', 'bank.movement.tab.outgoing', [['field' => 'direction', 'op' => 'is', 'value' => 'outgoing']], 2, false],
                ['unmatched', 'bank.movement.tab.unmatched', [['field' => 'direction', 'op' => 'is', 'value' => 'incoming'], ['field' => 'state', 'op' => 'is', 'value' => 'unmatched']], 3, true],
                ['outgoing_unmatched', 'bank.movement.tab.outgoing_unmatched', [['field' => 'direction', 'op' => 'is', 'value' => 'outgoing'], ['field' => 'state', 'op' => 'is', 'value' => 'unmatched']], 4, true],
                ['messages', 'bank.movement.tab.messages', [['field' => 'bank_mail', 'op' => 'is', 'value' => true]], 5, true],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position, $view->showCount], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsNameTheirValuesAndTheAccountsAreTheFirmsOwn(): void
    {
        $fields = BankMovementLists::of(['account-a' => 'Main', 'account-b' => 'CZ5508000000001234567899'])->fields;

        self::assertSame(['direction', 'state', 'account', 'bank_mail'], array_keys($fields));
        self::assertSame(['incoming' => 'bank.movement.tab.incoming', 'outgoing' => 'bank.movement.tab.outgoing'], $fields['direction']->optionLabels);
        self::assertSame(['recorded', 'unmatched', 'paired', 'own_transfer', 'possible_duplicate'], $fields['state']->options);
        self::assertSame('bank.movement.state.own_transfer', $fields['state']->optionLabels['own_transfer']);
        self::assertSame(['account-a', 'account-b'], $fields['account']->options);
        self::assertSame(['account-a' => 'Main', 'account-b' => 'CZ5508000000001234567899'], $fields['account']->optionLabels);
        self::assertSame([FieldType::Enum, 'bank.movement.list.account'], [$fields['account']->type, $fields['account']->label]);
        self::assertSame([FieldType::Boolean, 'bank.movement.field.bank_mail'], [$fields['bank_mail']->type, $fields['bank_mail']->label]);
        self::assertSame(['direction', 'state', 'bank_mail'], array_keys(BankMovementLists::of([])->fields), 'No account, no account field.');
    }

    public function testTheListNamesEachAccountByItsLabelOrItsIban(): void
    {
        $stores = new ScreenStores([
            'account-a' => ['label' => 'Main', 'iban' => 'CZ6508000000192000145399'],
            'account-b' => ['label' => '', 'iban' => 'CZ5508000000001234567899'],
        ], 0, 0);
        $lists = new BankMovementLists($stores->movements);

        self::assertSame(['account-a' => 'Main', 'account-b' => 'CZ5508000000001234567899'], $lists->list()->fields['account']->optionLabels);
        self::assertEquals([$lists->list()], iterator_to_array($lists->lists()));
    }

    public function testOnlyAViewAskingForTheBankMailShowsIt(): void
    {
        self::assertTrue(BankMovementLists::showsMail(ViewFilter::all(new Condition('bank_mail', 'is', true))));
        self::assertTrue(BankMovementLists::showsMail(ViewFilter::all(new Condition('direction', 'is', 'incoming'), new Condition('bank_mail', 'is', true))));
        self::assertFalse(BankMovementLists::showsMail(ViewFilter::all(new Condition('bank_mail', 'is', false))));
        self::assertFalse(BankMovementLists::showsMail(new ViewFilter()));
    }
}
