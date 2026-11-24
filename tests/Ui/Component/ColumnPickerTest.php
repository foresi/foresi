<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\ColumnPicker;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ColumnPickerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        self::bootKernel();
        // The forms of the views carry a CSRF token, which lives in the session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    private const array PROPS = [
        'id' => 'cols',
        'action' => '/list',
        'keep' => ['view' => 'paid', 'page' => '2', 'q' => null],
        'columns' => [
            ['key' => 'email', 'label' => 'Email', 'visible' => true, 'locked' => false],
            ['key' => 'number', 'label' => 'Number', 'visible' => true, 'locked' => true],
            ['key' => 'kind', 'label' => 'Kind', 'visible' => false, 'locked' => false],
        ],
        'density' => 'comfortable',
        'resetHref' => '/list?view=paid',
    ];

    public function testItIsAButtonThatOpensASmallSheetWithAGetFormToTheList(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-action="column-picker#open"', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        self::assertStringContainsString('aria-controls="cols-sheet"', $html);
        self::assertStringContainsString('w-[400px]', $html);
        self::assertMatchesRegularExpression('#<form method="get" action="/list"#', $html);
        self::assertStringContainsString('<input type="hidden" name="view" value="paid">', $html);
        self::assertStringNotContainsString('name="page"', $html);
        self::assertStringContainsString('data-column-picker-reset="true" href="/list?view=paid"', $html);
    }

    public function testEachColumnHasACheckboxInOrderAndTheLockedOneLeadsAndCannotBeHidden(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('#data-key="number".*data-key="email".*data-key="kind"#s', $html);
        self::assertMatchesRegularExpression('#<input type="hidden" name="cols\[\]" value="number"#', $html);
        self::assertMatchesRegularExpression('#id="cols-number" checked disabled#', $html);
        self::assertMatchesRegularExpression('#name="cols\[\]" value="email" checked#', $html);
        self::assertDoesNotMatchRegularExpression('#name="cols\[\]" value="kind" checked#', $html);
    }

    public function testEveryMovableRowHasMoveButtonsAndThereIsALiveRegion(): void
    {
        $html = $this->render();

        // Email; the locked number and Kind, which is to add, do not move.
        self::assertSame(1, substr_count($html, 'data-column-picker-up'));
        self::assertSame(1, substr_count($html, 'data-column-picker-down'));
        self::assertStringContainsString('aria-label="Posunout Email nahoru"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('data-column-picker-moved-value="{label} je teď na pozici {position} z {total}."', $html);
    }

    public function testTheHiddenColumnsAreToAddAfterTheShownOnes(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Přidat sloupec', $html);
        self::assertMatchesRegularExpression('#data-key="email".*data-column-picker-add.*data-column-picker-target="option" data-key="kind"#s', $html);
        self::assertMatchesRegularExpression('#<input type="checkbox" id="cols-kind" name="cols\[\]" value="kind" class#', $html);
    }

    public function testWithNothingToAddThereIsNoAddPart(): void
    {
        $html = $this->render(['columns' => [['key' => 'number', 'label' => 'Number', 'visible' => true, 'locked' => true]]]);

        self::assertStringNotContainsString('data-column-picker-add', $html);
    }

    public function testTheDensityIsARadioGroupWithTheCurrentOneChecked(): void
    {
        $html = $this->render(['density' => 'dense']);

        self::assertStringContainsString('name="density" value="comfortable" class', $html);
        self::assertStringContainsString('name="density" value="dense" checked', $html);
    }

    public function testSearchShowsOnlyPastTenColumns(): void
    {
        self::assertStringNotContainsString('data-column-picker-target="search"', $this->render());

        // Shown or to add, the search goes over all of them.
        $columns = array_map(static fn (int $i): array => ['key' => 'c'.$i, 'label' => 'C'.$i, 'visible' => 1 === $i % 2, 'locked' => false], range(1, 11));
        self::assertStringContainsString('data-column-picker-target="search"', $this->render(['columns' => $columns]));
    }

    public function testAModifiedListMarksTheButton(): void
    {
        self::assertStringNotContainsString(', změněno', $this->render());
        self::assertStringNotContainsString('data-column-picker-modified', $this->render());
        $html = $this->render(['modified' => true]);
        self::assertStringContainsString('aria-label="Nastavení seznamu, změněno"', $html);
        self::assertStringContainsString('data-column-picker-modified', $html);
    }

    public function testTheButtonIsASmallIconWithItsLabelAsNameAndTooltip(): void
    {
        $html = $this->render();
        self::assertMatchesRegularExpression('/<button[^>]*aria-label="Nastavení seznamu" title="Nastavení seznamu"[^>]*>/', $html);
        self::assertStringContainsString('data-column-picker-open', $html);
        self::assertStringNotContainsString('ml-auto', $html);
    }

    public function testAnUnknownDensityIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->render(['density' => 'huge']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidProps(): iterable
    {
        yield 'no id' => [['id' => '']];
        yield 'no action' => [['action' => '']];
        yield 'no reset link' => [['resetHref' => '']];
        yield 'an unsafe action' => [['action' => 'javascript:alert(1)']];
        yield 'an unsafe reset link' => [['resetHref' => 'javascript:alert(1)']];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('invalidProps')]
    public function testItRefusesWhatItCannotRender(array $props): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mount($props);
    }

    public function testAColumnIsHiddenAndMovableUnlessItSaysOtherwiseAndALockedOneIsAlwaysShown(): void
    {
        $picker = $this->mount(['columns' => [
            ['key' => 'due.date', 'label' => 'Due'],
            ['key' => 'name', 'label' => 'Name', 'visible' => false, 'locked' => true],
        ]]);

        self::assertSame([['key' => 'name', 'label' => 'Name', 'visible' => true, 'locked' => true, 'id' => 'cols-name']], $picker->shown());
        self::assertSame([['key' => 'due.date', 'label' => 'Due', 'visible' => false, 'locked' => false, 'id' => 'cols-due-date']], $picker->addable());
    }

    public function testTheShownColumnsAreAListAfterAHiddenOne(): void
    {
        $picker = $this->mount(['columns' => [
            ['key' => 'note', 'label' => 'Note'],
            ['key' => 'name', 'label' => 'Name', 'visible' => true],
        ]]);

        self::assertSame([0], array_keys($picker->shown()));
    }

    public function testTenColumnsNeedNoSearch(): void
    {
        $columns = array_map(static fn (int $i): array => ['key' => 'c'.$i, 'label' => 'C'.$i], range(1, 10));

        self::assertFalse($this->mount(['columns' => $columns])->searchable());
    }

    public function testTheFormKeepsTheListsStateButNotItsWorkingDisplayOrPage(): void
    {
        $picker = $this->mount(['keep' => ['view' => 'paid', 'q' => '', 'archived' => true, 'draft' => false, 'limit' => 5, 'cols' => 'a', 'density' => 'dense', 'page' => '2', 'sort' => null]]);

        self::assertSame(['view' => 'paid', 'archived' => '1', 'draft' => '0', 'limit' => '5'], $picker->hidden());
    }

    /**
     * @param array<string, mixed> $props
     */
    private function mount(array $props = []): ColumnPicker
    {
        $component = $this->mountTwigComponent('Ui:ColumnPicker', $props + self::PROPS);
        self::assertInstanceOf(ColumnPicker::class, $component);

        return $component;
    }

    public function testTheQuickFiltersAreOneListOfEveryFieldTheCheckedOnesFirstInOrder(): void
    {
        $crawler = new Crawler($this->render(['quick' => [
            ['key' => 'due', 'label' => 'Due', 'pinned' => true],
            ['key' => 'customer', 'label' => 'Customer', 'pinned' => true],
            ['key' => 'paid', 'label' => 'Paid', 'pinned' => false],
        ]]));

        $quick = $crawler->filter('[data-column-picker-quick]');
        self::assertSame('Rychlé filtry', trim($quick->filter('legend')->text()));
        self::assertSame(['due', 'customer', 'paid'], $quick->filter('[data-column-picker-target=pinRow]')->each(static fn (Crawler $row): string => (string) $row->attr('data-key')));
        self::assertSame(['due', 'customer'], $quick->filter('[data-column-picker-target=pinRow] input[name="pins[]"]:checked')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertCount(3, $quick->filter('[data-column-picker-target=pinRow] [data-column-picker-up]'), 'Every quick filter moves as a column does.');
        self::assertCount(0, $crawler->filter('[data-column-picker-quick-add], [data-column-picker-presets]'), 'One list, no "Add quick filter" and no presets.');
        self::assertSame('', $crawler->filter('input[type=hidden][name="pins[]"]')->attr('value'), 'With no box checked the form still says none.');
        self::assertLessThan(
            (int) strpos($crawler->html(), 'data-column-picker-density'),
            (int) strpos($crawler->html(), 'data-column-picker-quick'),
            'The quick filters come before the columns and the density.',
        );
    }

    public function testTheQuickFiltersKeepTheirOrderEachWithItsOwnId(): void
    {
        $picker = $this->mount(['quick' => [
            ['key' => 'due', 'label' => 'Due', 'pinned' => true],
            ['key' => 'paid', 'label' => 'Paid'],
            ['key' => 'customer.city', 'label' => 'City', 'pinned' => true],
            ['key' => 'kind', 'label' => 'Kind', 'pinned' => false],
        ]]);

        self::assertSame([
            ['key' => 'due', 'label' => 'Due', 'pinned' => true, 'id' => 'cols-pin-due'],
            ['key' => 'paid', 'label' => 'Paid', 'pinned' => false, 'id' => 'cols-pin-paid'],
            ['key' => 'customer.city', 'label' => 'City', 'pinned' => true, 'id' => 'cols-pin-customer-city'],
            ['key' => 'kind', 'label' => 'Kind', 'pinned' => false, 'id' => 'cols-pin-kind'],
        ], $picker->quickViews(), 'A quick filter that does not say it is pinned is not.');
    }

    public function testAListWithoutColumnsShowsItsQuickFiltersAlone(): void
    {
        $crawler = new Crawler($this->render(['columns' => [], 'density' => null, 'quick' => [['key' => 'person', 'label' => 'Person', 'pinned' => true]]]));

        self::assertCount(1, $crawler->filter('[data-column-picker-quick]'));
        self::assertCount(0, $crawler->filter('input[name="cols[]"], input[name=cols], [data-column-picker-density]'));
        self::assertCount(1, $crawler->filter('[data-column-picker-reset]'));
    }

    public function testNoQuickFiltersNoSection(): void
    {
        $crawler = new Crawler($this->render());

        self::assertCount(0, $crawler->filter('[data-column-picker-quick], input[name="pins[]"], input[name=pins]'));
    }

    public function testThePinsOfTheAddressAreNotKeptAsHiddenFields(): void
    {
        $crawler = new Crawler($this->render(['keep' => ['view' => 'paid', 'pins' => 'due'], 'quick' => [['key' => 'due', 'label' => 'Due', 'pinned' => true]]]));

        self::assertCount(0, $crawler->filter('input[type=hidden][name=pins][value=due]'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props = []): string
    {
        return (string) $this->renderTwigComponent('Ui:ColumnPicker', $props + self::PROPS);
    }

    public function testTheViewsComeFirstAllLockedTheTabsCheckedAndOnlyAnOwnerOrdersThem(): void
    {
        $crawler = new Crawler($this->render(['manage' => $this->manage(true), 'viewBar' => ['modified' => false], 'quick' => [['key' => 'due', 'label' => 'Due', 'pinned' => true]]]));

        $views = $crawler->filter('[data-settings-views]');
        self::assertSame('Pohledy', trim($views->filter('h3')->text()));
        self::assertCount(1, $views->filter('[data-settings-view=all] input[type=checkbox][checked][disabled]'));
        self::assertSame(['v1', 'v2'], $views->filter('[data-column-picker-target=viewRow]')->each(static fn (Crawler $row): string => (string) $row->attr('data-ref')));
        self::assertNotNull($views->filter('[data-settings-view=unpaid] input[type=checkbox]')->attr('checked'));
        self::assertNull($views->filter('[data-settings-view=mine] input[type=checkbox]')->attr('checked'));
        self::assertCount(2, $views->filter('[data-column-picker-up]'));
        self::assertCount(1, $views->filter('form[data-column-picker-target=viewOrder]'));
        self::assertCount(1, $views->filter('[data-view-new]'));
        self::assertLessThan(
            (int) strpos($crawler->html(), 'data-column-picker-quick'),
            (int) strpos($crawler->html(), 'data-settings-views'),
            'The views come before the quick filters.',
        );

        $member = new Crawler($this->render(['manage' => $this->manage(false)]));
        self::assertCount(0, $member->filter('[data-column-picker-target=viewRow], form[data-column-picker-target=viewOrder], [data-settings-views] [data-column-picker-up]'));
        self::assertNotNull($member->filter('[data-settings-view=unpaid] input[type=checkbox]')->attr('disabled'));
    }

    public function testEachViewHasItsMenuRenameShowCountDuplicateAndHideOrDelete(): void
    {
        $crawler = new Crawler($this->render(['manage' => $this->manage(true)]));

        $shipped = $crawler->filter('[data-settings-view=unpaid] [data-view-row-menu]');
        self::assertCount(1, $shipped->filter('[data-view-rename]'));
        self::assertSame('0', $shipped->filter('[data-view-show-count]')->closest('form')?->filter('input[name=showCount]')->attr('value'), 'Its count shows, so the item turns it off.');
        self::assertCount(1, $shipped->filter('[data-view-duplicate]'));
        self::assertCount(1, $shipped->filter('[data-view-hide]'));
        self::assertCount(0, $shipped->filter('[data-view-delete], [data-view-open]'), 'A tab needs no Open; a shipped view is hidden, not deleted.');

        $own = $crawler->filter('[data-settings-view=mine] [data-view-row-menu]');
        self::assertCount(1, $own->filter('[data-view-delete]'));
        self::assertCount(1, $own->filter('[data-view-open]'), 'A view that is no tab opens from here.');
        self::assertCount(1, $own->filter('[data-view-visibility]'));
    }

    public function testWithoutViewsThereIsNoViewsSection(): void
    {
        self::assertCount(0, new Crawler($this->render())->filter('[data-settings-views]'));
    }

    /**
     * @return array<string, mixed>
     */
    private function manage(bool $owner): array
    {
        $row = static fn (string $ref, string $key, string $name, bool $primary, bool $shipped, bool $showCount = false): array => ['ref' => $ref, 'key' => $key, 'name' => $name, 'translated' => false, 'private' => !$shipped, 'primary' => $primary, 'showCount' => $showCount, 'editable' => $owner || !$shipped, 'shipped' => $shipped, 'href' => '/invoices?view='.$key];

        return [
            'all' => ['name' => 'All', 'translated' => false],
            'tabs' => [$row('v1', 'unpaid', 'Unpaid', true, true, true)],
            'more' => [$row('v2', 'mine', 'Mine', false, false)],
            'hidden' => [],
            'canReorder' => $owner,
            'maxTabs' => 6,
            'primaryFull' => false,
            'canRestore' => $owner,
            'list' => 'invoices',
            'return' => '/invoices',
        ];
    }
}
