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

        self::assertSame(['cols' => 'name,email', 'density' => 'dense', 'group' => 'kind', 'pins' => 'city,kind', 'q' => 'example'], $query);
    }

    public function testWhatTheDisplayLeavesOutIsTheViewsOwn(): void
    {
        self::assertSame(['cols' => 'name', 'density' => ''], $this->queryOf(['columns' => [['key' => 'name']], 'pinned' => 'not a list', 'group_by' => 7]));
        self::assertSame(['cols' => '', 'density' => '', 'pins' => ''], $this->queryOf(['pinned' => []]), 'A display that pins none pins none.');
    }

    /**
     * The query WorkingDisplay is asked to read for a person's remembered display.
     *
     * @param array<string, mixed>  $remembered
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    private function queryOf(array $remembered, array $query = []): array
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
        $states = new readonly class($remembered) implements SavedViewUserStates {
            /**
             * @param array<string, mixed> $remembered
             */
            public function __construct(private array $remembered)
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
                throw new \LogicException('Nothing is forgotten from a read alone.');
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
