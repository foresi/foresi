<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\SavedViewUserStates;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use PHPUnit\Framework\TestCase;

/**
 * A display the person last used on a view comes back as the address WorkingDisplay reads.
 */
final class RememberedDisplayTest extends TestCase
{
    public function testARememberedDisplayIsReadAsItsAddress(): void
    {
        $query = $this->queryOf(['columns' => [['key' => 'name'], 'not a column', ['key' => 'email', 'width' => 200]], 'density' => 'dense', 'group_by' => 'kind', 'pinned' => ['city', 7, 'kind']], ['q' => 'example']);

        self::assertSame(['cols' => 'name,email', 'density' => 'dense', 'widths' => 'email:200', 'group' => 'kind', 'pins' => 'city,kind', 'q' => 'example'], $query);
    }

    public function testWhatTheDisplayLeavesOutIsTheViewsOwn(): void
    {
        self::assertSame(['cols' => 'name,kind', 'density' => '', 'widths' => ''], $this->queryOf(['columns' => [['key' => 'name'], ['key' => 'kind', 'width' => 0]], 'pinned' => 'not a list', 'group_by' => 7]), 'A width of no px is none.');
        self::assertSame(['cols' => '', 'density' => '', 'widths' => '', 'pins' => ''], $this->queryOf(['pinned' => []]), 'A display that pins none pins none.');
    }

    public function testTheSettingsSheetsColumnsKeepTheWidthsDragged(): void
    {
        $remembered = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'email', 'width' => 180]]];

        self::assertSame(['cols' => 'name,kind', 'density' => 'dense', 'widths' => 'name:240,email:180'], $this->queryOf($remembered, ['cols' => 'name,kind', 'density' => 'dense'], named: true));
        self::assertSame(['density' => '', 'widths' => ''], $this->queryOf($remembered, ['density' => '', 'widths' => ''], named: true), 'Reset names the view\'s own widths.');
    }

    public function testADraggedColumnKeepsTheColumnsHidden(): void
    {
        $remembered = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'email']], 'density' => 'dense', 'pinned' => ['city']];

        self::assertSame(['widths' => 'name:300', 'cols' => 'name,email', 'density' => 'dense', 'pins' => 'city'], $this->queryOf($remembered, ['widths' => 'name:300'], named: true));
    }

    /**
     * The query WorkingDisplay is asked to read for a person's remembered display.
     *
     * @param array<string, mixed>  $remembered
     * @param array<string, string> $query
     * @param bool                  $named      whether the address names a display, so a read may forget the one remembered
     *
     * @return array<array-key, mixed>
     */
    private function queryOf(array $remembered, array $query = [], bool $named = false): array
    {
        $working = new class implements WorkingDisplay {
            /** @var array<array-key, mixed> */
            public array $query = [];

            public function read(string $list, array $context, ?array $saved, array $query, string $density = ListDisplay::COMFORTABLE, array $groups = [], ?array $pins = null): WorkingState
            {
                $this->query = $query;

                return new WorkingState(new ListDisplay([]), null, [], $density, false, []);
            }
        };
        $states = new readonly class($remembered, $named) implements SavedViewUserStates {
            /**
             * @param array<string, mixed> $remembered
             */
            public function __construct(private array $remembered, private bool $named)
            {
            }

            public function display(string $viewId, string $userId): array
            {
                return $this->remembered;
            }

            public function remember(string $viewId, string $userId, array $display): void
            {
                throw new \LogicException('Nothing is remembered from a read alone.');
            }

            public function forget(string $viewId, string $userId): void
            {
                if (!$this->named) {
                    throw new \LogicException('Nothing is forgotten from a read alone.');
                }
            }
        };
        $actor = self::createStub(ActorRole::class);
        $actor->method('userId')->willReturn('user-1');
        $views = new \ReflectionClass(SavedViews::class)->newInstanceWithoutConstructor();
        $view = new ListView('party.contacts.mine', 'Mine', false, ViewFilter::all(), true, Placement::Primary, id: 'view-1');

        new RememberedDisplay($working, $states, $views, $actor)->read('party.contacts', [], 'party.contacts', $view, $query);

        return $working->query;
    }
}
