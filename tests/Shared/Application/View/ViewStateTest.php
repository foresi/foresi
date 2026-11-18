<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Domain\View\Placement;
use PHPUnit\Framework\TestCase;

final class ViewStateTest extends TestCase
{
    public function testTheAddressSortIsAFieldWithAMinusForDescending(): void
    {
        self::assertSame([['field' => 'dueDate', 'dir' => 'desc']], ViewState::sortOf('-dueDate'));
        self::assertSame([['field' => 'number', 'dir' => 'asc']], ViewState::sortOf('number'));
        self::assertSame([], ViewState::sortOf(''));
        self::assertSame([], ViewState::sortOf('-'));
        self::assertSame('-dueDate', ViewState::sortParam([['field' => 'dueDate', 'dir' => 'desc'], ['field' => 'number', 'dir' => 'asc']]));
        self::assertSame('number', ViewState::sortParam([['field' => 'number', 'dir' => 'asc']], '-issueDate'));
        self::assertSame('-issueDate', ViewState::sortParam([], '-issueDate'));
        self::assertSame('', ViewState::sortParam([]));
    }

    public function testAViewIsModifiedByItsFilterSortOrDisplayOnly(): void
    {
        $view = new ListView('late', 'Late', false, ViewFilter::all(), false, Placement::Primary, sort: [['field' => 'number', 'dir' => 'asc']]);

        self::assertFalse(ViewState::isModified($view, ViewFilter::all(), [['field' => 'number', 'dir' => 'asc']], false));
        self::assertTrue(ViewState::isModified($view, ViewFilter::all(), [], false));
        self::assertTrue(ViewState::isModified($view, ViewFilter::all(), [['field' => 'number', 'dir' => 'asc']], true));
        self::assertTrue(ViewState::isModified($view, ViewFilter::all(new Condition('payment', 'is', 'unpaid')), [['field' => 'number', 'dir' => 'asc']], false));
    }
}
