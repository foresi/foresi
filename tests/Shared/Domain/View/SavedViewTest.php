<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Domain\View;

use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
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

    private function view(string $name): SavedView
    {
        return SavedView::shipped('invoicing.invoices', 'invoicing.invoices.paid', $name, self::FILTERS, [], true, Placement::Primary, 1, new \DateTimeImmutable('2026-11-18'));
    }
}
