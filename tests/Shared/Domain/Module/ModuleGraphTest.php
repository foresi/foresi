<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Module;

use App\Shared\Domain\Module\GraphProblem;
use App\Shared\Domain\Module\InvalidManifest;
use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Domain\Module\ModuleKind;
use App\Shared\Domain\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class ModuleGraphTest extends TestCase
{
    public function testOrdersDependenciesFirst(): void
    {
        $graph = new ModuleGraph([
            $this->module('timesheet', requires: ['work']),
            $this->module('work', requires: ['shared']),
            $this->module('shared', ModuleKind::Core),
        ]);

        self::assertSame(['shared', 'work', 'timesheet'], $this->ids($graph->dependencyOrder()));
    }

    public function testListsModulesSortedById(): void
    {
        $graph = new ModuleGraph([$this->module('work'), $this->module('audit', ModuleKind::Core)]);

        self::assertSame(['audit', 'work'], array_map(static fn (ModuleManifest $m): string => $m->id->value, $graph->all()));
        self::assertTrue($graph->has(ModuleId::of('work')));
        self::assertFalse($graph->has(ModuleId::of('party')));
        self::assertSame('work', $graph->get(ModuleId::of('work'))->id->value);
    }

    public function testUnknownModuleIsRefused(): void
    {
        $this->expectException(InvalidManifest::class);

        new ModuleGraph([$this->module('work')])->get(ModuleId::of('party'));
    }

    public function testMissingHardDependencyIsRefused(): void
    {
        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessageIsOrContains('timesheet requires work');

        new ModuleGraph([$this->module('timesheet', requires: ['work'])]);
    }

    public function testAbsentOptionalDependencyIsFine(): void
    {
        $graph = new ModuleGraph([$this->module('timesheet', optional: ['invoicing'])]);

        self::assertSame([], $graph->problems());
    }

    public function testReportsCycleOnceInCanonicalForm(): void
    {
        $graph = new ModuleGraph([
            $this->module('party', requires: ['identity']),
            $this->module('identity', requires: ['party']),
        ]);

        self::assertSame(['cycle:identity>party>identity'], $this->codes($graph->problems()));
    }

    public function testCycleFollowsTheEdges(): void
    {
        $graph = new ModuleGraph([
            $this->module('identity', requires: ['party']),
            $this->module('party', requires: ['import']),
            $this->module('import', requires: ['identity']),
        ]);

        self::assertSame(['cycle:identity>party>import>identity'], $this->codes($graph->problems()));
    }

    public function testReportsCoreRequiringModule(): void
    {
        $graph = new ModuleGraph([
            $this->module('identity', ModuleKind::Core, requires: ['party']),
            $this->module('party'),
            $this->module('work', requires: ['identity']),
        ]);

        self::assertSame(['core-requires-module:identity>party'], $this->codes($graph->problems()));
    }

    public function testReportsCoreRequiringUi(): void
    {
        $graph = new ModuleGraph([
            $this->module('identity', ModuleKind::Core, requires: ['ui']),
            $this->module('ui', ModuleKind::Ui),
        ]);

        self::assertSame(['core-requires-module:identity>ui'], $this->codes($graph->problems()));
    }

    public function testCyclesDoNotBreakOrdering(): void
    {
        $graph = new ModuleGraph([
            $this->module('work', requires: ['party']),
            $this->module('party', requires: ['identity', 'shared']),
            $this->module('identity', requires: ['party', 'shared']),
            $this->module('shared', ModuleKind::Core),
        ]);

        self::assertSame(['shared', 'identity', 'party', 'work'], $this->ids($graph->dependencyOrder()));
    }

    public function testDuplicateIdIsRefused(): void
    {
        $this->expectException(InvalidManifest::class);

        new ModuleGraph([$this->module('work'), $this->module('work')]);
    }

    public function testIdInRequiresAndOptionalIsRefused(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->module('timesheet', requires: ['invoicing'], optional: ['invoicing']);
    }

    public function testModuleRequiringItselfIsRefused(): void
    {
        $this->expectException(InvalidManifest::class);

        $this->module('work', requires: ['work']);
    }

    /**
     * @param list<string> $requires
     * @param list<string> $optional
     */
    private function module(string $id, ModuleKind $kind = ModuleKind::Module, array $requires = [], array $optional = []): ModuleManifest
    {
        $namespace = 'App\\'.str_replace('-', '', ucwords($id, '-')).'\\';

        return new ModuleManifest(
            ModuleId::of($id),
            $kind,
            'foresi/'.$id,
            $namespace,
            array_map(ModuleId::of(...), $requires),
            array_map(ModuleId::of(...), $optional),
        );
    }

    /**
     * @param list<ModuleId> $ids
     *
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_map(static fn (ModuleId $id): string => $id->value, $ids);
    }

    /**
     * @param list<GraphProblem> $problems
     *
     * @return list<string>
     */
    private function codes(array $problems): array
    {
        return array_map(static fn (GraphProblem $problem): string => $problem->code, $problems);
    }
}
