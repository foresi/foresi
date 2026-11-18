<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Domain\View;

use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use PHPUnit\Framework\TestCase;

final class SavedViewTest extends TestCase
{
    private const array FILTERS = ['match' => 'all', 'conditions' => [['field' => 'payment', 'op' => 'is', 'value' => 'paid']]];

    public function testAViewIsNamedInOneToSixtyCharacters(): void
    {
        self::assertSame(str_repeat('a', 60), $this->view(str_repeat('a', 60))->name());
        self::assertSame(str_repeat('č', 60), $this->view(str_repeat('č', 60))->name());

        foreach (['', '   ', str_repeat('a', 61)] as $name) {
            try {
                $this->view($name);
                self::fail(\sprintf('The name "%s" is refused.', $name));
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testReshippingWhatIsStoredChangesNothingWhateverTheOrderOfItsKeys(): void
    {
        $view = $this->view('Paid');
        $now = new \DateTimeImmutable('2026-11-19');
        $reordered = ['conditions' => [['value' => 'paid', 'op' => 'is', 'field' => 'payment']], 'match' => 'all'];

        self::assertFalse($view->reship('Paid', $reordered, [], true, Placement::Primary, 1, $now));
        self::assertSame(self::FILTERS, $view->filters());
        self::assertEquals(new \DateTimeImmutable('2026-11-18'), $view->updatedAt());
    }

    public function testReshippingAChangeTakesIt(): void
    {
        $view = $this->view('Paid');
        $now = new \DateTimeImmutable('2026-11-19');
        $filters = ['match' => 'all', 'conditions' => [['field' => 'payment', 'op' => 'is', 'value' => 'unpaid']]];

        self::assertTrue($view->reship('Paid', $filters, [], true, Placement::Primary, 1, $now));
        self::assertSame($filters, $view->filters());
        self::assertSame($now, $view->updatedAt());
        self::assertTrue($view->reship('Paid', $filters, [], true, Placement::Overflow, 1, $now));
        self::assertTrue($view->reship('Paid', $filters, [['field' => 'number', 'dir' => 'asc']], true, Placement::Overflow, 1, $now));
        self::assertSame([['field' => 'number', 'dir' => 'asc']], $view->sort());
    }

    public function testAReshippedNameIsCheckedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->view('Paid')->reship(' ', self::FILTERS, [], true, Placement::Primary, 1, new \DateTimeImmutable('2026-11-19'));
    }

    public function testAMadeViewIsItsOwnersAndTouchedByThem(): void
    {
        $view = SavedView::made('invoicing.invoices', 'user-1', 'Mine', Visibility::Private, self::FILTERS, [], ['density' => 'dense'], true, Placement::Overflow, 4, new \DateTimeImmutable('2026-11-18'));

        self::assertSame('user-1', $view->ownerId());
        self::assertSame('user-1', $view->updatedBy());
        self::assertNull($view->systemKey());
        self::assertSame(['density' => 'dense'], $view->display());
        self::assertSame(4, $view->position());
        self::assertSame(Placement::Overflow, $view->placement());
        self::assertFalse($view->isPristine());
    }

    public function testAnEditChangesWhatItIsGivenAndKeepsTheRest(): void
    {
        $view = $this->view('Paid');
        $now = new \DateTimeImmutable('2026-11-19');
        $filters = ['match' => 'all', 'conditions' => []];
        $sort = [['field' => 'number', 'dir' => 'desc']];

        $view->edit('user-1', $now);
        self::assertSame(['Paid', self::FILTERS, [], true, Placement::Primary, 1, false], [$view->name(), $view->filters(), $view->sort(), $view->showCount(), $view->placement(), $view->position(), $view->isHidden()]);
        self::assertSame('user-1', $view->updatedBy());
        self::assertSame($now, $view->updatedAt());
        self::assertFalse($view->isPristine());

        $view->edit('user-2', $now, 'Settled', $filters, $sort, false, Placement::Overflow, 7, true);
        self::assertSame(['Settled', $filters, $sort, false, Placement::Overflow, 7, true], [$view->name(), $view->filters(), $view->sort(), $view->showCount(), $view->placement(), $view->position(), $view->isHidden()]);
        self::assertSame('user-2', $view->updatedBy());

        $view->edit('user-2', $now, showCount: false, position: 0, hidden: false);
        self::assertSame(0, $view->position());
        self::assertFalse($view->isHidden());
        self::assertSame('Settled', $view->name());
    }

    public function testAnEditedNameIsCheckedToo(): void
    {
        $view = $this->view('Paid');

        foreach ([' ', str_repeat('a', 61)] as $name) {
            try {
                $view->edit('user-1', new \DateTimeImmutable('2026-11-19'), name: $name);
                self::fail('The name was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame('Paid', $view->name());
        self::assertNull($view->updatedBy());
    }

    public function testRedisplayingSetsTheDisplayAndTouchesTheView(): void
    {
        $view = $this->view('Paid');
        $now = new \DateTimeImmutable('2026-11-19');

        $view->redisplay(['density' => 'dense'], 'user-1', $now);

        self::assertSame(['density' => 'dense'], $view->display());
        self::assertSame('user-1', $view->updatedBy());
        self::assertSame($now, $view->updatedAt());
        $view->redisplay(null, 'user-1', $now);
        self::assertNull($view->display());
    }

    public function testAShippedViewStaysSharedButAMadeOneChangesVisibility(): void
    {
        $now = new \DateTimeImmutable('2026-11-19');
        $shipped = $this->view('Paid');

        $shipped->changeVisibility(Visibility::Shared, 'user-1', $now);
        self::assertSame('user-1', $shipped->updatedBy());
        self::assertSame($now, $shipped->updatedAt());
        try {
            $shipped->changeVisibility(Visibility::Private, 'user-1', $now);
            self::fail('A shipped view has no owner to be private to.');
        } catch (\LogicException) {
            self::assertSame(Visibility::Shared, $shipped->visibility());
        }

        $made = SavedView::made('invoicing.invoices', 'user-1', 'Mine', Visibility::Shared, self::FILTERS, [], null, true, Placement::Primary, 1, new \DateTimeImmutable('2026-11-18'));
        $made->changeVisibility(Visibility::Private, 'user-2', $now);
        self::assertSame(Visibility::Private, $made->visibility());
        self::assertSame('user-2', $made->updatedBy());
        self::assertSame($now, $made->updatedAt());
    }

    public function testRestoringBringsAShippedViewBackToTheModule(): void
    {
        $view = $this->view('Paid');
        $now = new \DateTimeImmutable('2026-11-19');
        $view->edit('user-1', $now, name: 'Settled', hidden: true);
        $view->redisplay(['density' => 'dense'], 'user-1', $now);

        $view->restore('Paid', self::FILTERS, [], true, Placement::Primary, 1, $now);

        self::assertTrue($view->isPristine());
        self::assertNull($view->display());
        self::assertSame('Paid', $view->name());
        self::assertSame(Visibility::Shared, $view->visibility());
        self::assertSame($now, $view->updatedAt());
    }

    public function testRestoringChecksTheNameAndNeedsAShippedView(): void
    {
        $now = new \DateTimeImmutable('2026-11-19');
        try {
            $this->view('Paid')->restore(' ', self::FILTERS, [], true, Placement::Primary, 1, $now);
            self::fail('The name was accepted.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\LogicException::class);
        SavedView::made('invoicing.invoices', 'user-1', 'Mine', Visibility::Private, self::FILTERS, [], null, true, Placement::Primary, 1, $now)->restore('Mine', self::FILTERS, [], true, Placement::Primary, 1, $now);
    }

    private function view(string $name): SavedView
    {
        return SavedView::shipped('invoicing.invoices', 'invoicing.invoices.paid', $name, self::FILTERS, [], true, Placement::Primary, 1, new \DateTimeImmutable('2026-11-18'));
    }
}
