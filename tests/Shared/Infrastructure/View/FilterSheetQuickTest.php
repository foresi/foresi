<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The quick filters of a view (FilterSheet): a chip a pinned field, a menu of links where the
 * field has values to pick, the sheet with a row for it where it has none.
 *
 * @phpstan-import-type Chip from FilterSheet
 * @phpstan-import-type Quick from FilterSheet
 */
final class FilterSheetQuickTest extends TestCase
{
    public function testTheListsOwnQuickFiltersStandInOrderWhenTheViewPinsNone(): void
    {
        $sheet = $this->sheet(new ViewFilter());

        self::assertSame(['payment', 'issue_date'], array_column($sheet['quick'], 'key'));
        self::assertSame(['payment', 'issue_date', 'customer', 'paid', 'state', 'client'], array_column($sheet['pinnable'], 'key'), 'The pinned come first in the Display sheet.');
        self::assertSame([true, true, false, false, false, false], array_column($sheet['pinnable'], 'pinned'));
    }

    public function testAnEnumThatTakesAnyOfPicksSeveralValuesEachLinkTogglingOne(): void
    {
        $filter = ViewFilter::all(new Condition('payment', 'is', 'unpaid'), new Condition('paid', 'is', true));
        $quick = $this->sheet($filter, ['payment'])['quick'][0];

        self::assertSame('values', $quick['pick']);
        self::assertTrue($quick['menu']);
        self::assertTrue($quick['multi']);
        self::assertTrue($quick['set']);
        self::assertSame('payment.unpaid', $quick['text']);
        $options = $quick['groups'][0]['options'];
        self::assertSame([false, true], array_column($options, 'checked'));
        self::assertSame(
            [['field' => 'paid', 'op' => 'is', 'value' => true], ['field' => 'payment', 'op' => 'is_any_of', 'value' => ['unpaid', 'paid']]],
            $this->conditions($options[0]['href']),
            'Picking another adds it.',
        );
        self::assertSame([['field' => 'paid', 'op' => 'is', 'value' => true]], $this->conditions($options[1]['href']), 'Picking a picked one drops it.');
        self::assertSame([['field' => 'paid', 'op' => 'is', 'value' => true]], $this->conditions($quick['clearHref']), 'Its ✕ clears its field alone.');
    }

    public function testADateOffersThePeriodsThenTheMonthsGivenNewestFirst(): void
    {
        $quick = $this->sheet(new ViewFilter(), ['issue_date'], ['issue_date' => ['2026-09', '2026-11', 'not a month']])['quick'][0];

        self::assertSame(['ui.view_filter.relative.this_month', 'ui.view_filter.relative.last_month', 'ui.view_filter.relative.this_quarter', 'ui.view_filter.relative.last_quarter', 'ui.view_filter.relative.this_year', 'ui.view_filter.relative.last_year'], array_column($quick['groups'][0]['options'], 'label'));
        self::assertSame('ui.view_filter.quick.by_month', $quick['groups'][1]['label']);
        self::assertCount(2, $quick['groups'][1]['options']);
        self::assertSame([['field' => 'issue_date', 'op' => 'is', 'value' => ['month' => '2026-11']]], $this->conditions($quick['groups'][1]['options'][0]['href']));
        self::assertSame([['field' => 'issue_date', 'op' => 'is', 'value' => ['relative' => 'last_month']]], $this->conditions($quick['groups'][0]['options'][1]['href']));
    }

    public function testAnEnumOfOneValueAtATimeReplacesItsValue(): void
    {
        $quick = $this->sheet(ViewFilter::all(new Condition('state', 'is', 'open')), ['state'])['quick'][0];

        self::assertFalse($quick['multi']);
        self::assertSame([['field' => 'state', 'op' => 'is', 'value' => 'closed']], $this->conditions($quick['groups'][0]['options'][1]['href']));
    }

    public function testAFieldWithNoValuesToPickOpensTheSheet(): void
    {
        $quick = $this->sheet(new ViewFilter(), ['customer'])['quick'][0];

        self::assertSame('sheet', $quick['pick']);
        self::assertFalse($quick['menu']);
        self::assertSame([], $quick['groups']);
        self::assertFalse($quick['set']);
        self::assertNull($quick['lookup']);
    }

    public function testAReferenceSearchesTheRecordsOfItsSourceThroughItsPicker(): void
    {
        $filter = ViewFilter::all(new Condition('client', 'is', 'c1'), new Condition('paid', 'is', true));
        $quick = $this->sheet($filter, ['client'], references: ['contact' => ['url' => '/contacts/pick', 'name' => static fn (string $id): string => 'Example s.r.o.']])['quick'][0];

        self::assertSame('lookup', $quick['pick']);
        self::assertTrue($quick['multi']);
        self::assertSame('Example s.r.o.', $quick['text']);
        $lookup = $quick['lookup'];
        self::assertIsArray($lookup);
        self::assertSame('/contacts/pick', $lookup['url']);
        self::assertSame('is_any_of', $lookup['op']);
        self::assertSame([['field' => 'paid', 'op' => 'is', 'value' => true]], json_decode($lookup['conditions'], true));
        self::assertSame([['id' => 'c1', 'label' => 'Example s.r.o.']], array_map(static fn (array $one): array => ['id' => $one['id'], 'label' => $one['label']], $lookup['selected']));
        self::assertSame([['field' => 'paid', 'op' => 'is', 'value' => true]], $this->conditions($lookup['selected'][0]['href']), 'A picked record is unpicked by its link.');
    }

    public function testAReferenceWithoutAPickerOpensTheSheet(): void
    {
        self::assertSame('sheet', $this->sheet(new ViewFilter(), ['client'])['quick'][0]['pick']);
    }

    public function testAConditionOnAPinnedFieldIsMarkedSoTheBarShowsItOnce(): void
    {
        $chips = $this->sheet(ViewFilter::all(new Condition('payment', 'is', 'paid'), new Condition('paid', 'is', true)), ['payment'])['chips'];

        self::assertSame([true, false], array_column($chips, 'pinned'));
        self::assertSame(['payment', 'paid'], array_column($chips, 'field'));
    }

    /**
     * @param list<string>|null                   $pinned
     * @param array<string, list<string>>         $months
     * @param array<string, array<string, mixed>> $references
     *
     * @return array{chips: list<Chip>, quick: list<Quick>, pinnable: list<array{key: string, label: string, pinned: bool}>, builder: array<string, mixed>}
     */
    private function sheet(ViewFilter $filter, ?array $pinned = null, array $months = [], array $references = []): array
    {
        $list = new ListDefinition('test.invoices', [
            new Field('payment', FieldType::Enum, 'Payment', options: ['paid', 'unpaid'], optionLabels: ['paid' => 'payment.paid', 'unpaid' => 'payment.unpaid']),
            new Field('issue_date', FieldType::Date, 'Issue date'),
            new Field('customer', FieldType::Text, 'Customer'),
            new Field('paid', FieldType::Boolean, 'Paid'),
            new Field('state', FieldType::Enum, 'State', ['is', 'is_not'], ['open', 'closed']),
            new Field('client', FieldType::Reference, 'Client', source: 'contact'),
        ], [new DefaultView('all', 'All')], ['payment', 'issue_date']);

        return new FilterSheet(new IdentityTranslator())->of($list, $filter, static fn (ViewFilter $next): string => '/invoices?'.http_build_query([WorkingFilter::PARAM => WorkingFilter::encode($next)]), $references, $pinned, $months); // @phpstan-ignore argument.type
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditions(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        $param = $query[WorkingFilter::PARAM] ?? null;
        self::assertIsString($param);
        $filter = json_decode((string) base64_decode(strtr($param, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($filter);
        $conditions = [];
        foreach (\is_array($filter['conditions'] ?? null) ? $filter['conditions'] : [] as $condition) {
            self::assertIsArray($condition);
            $conditions[] = array_filter($condition, \is_string(...), \ARRAY_FILTER_USE_KEY);
        }

        return $conditions;
    }
}
