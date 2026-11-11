<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * The installed modules and their dependencies. Every hard dependency must be
 * installed; an optional one may be absent. Cycles and a core that depends on
 * a business module are reported as problems, not refused, so that today's
 * graph can be read while they are being removed.
 */
final readonly class ModuleGraph
{
    /**
     * @var array<string, ModuleManifest> by id, sorted by id
     */
    private array $modules;

    /**
     * @param list<ModuleManifest> $modules
     */
    public function __construct(array $modules)
    {
        $byId = [];
        foreach ($modules as $module) {
            if (isset($byId[$module->id->value])) {
                throw new InvalidManifest(\sprintf('Module %s is installed twice.', $module->id->value));
            }
            $byId[$module->id->value] = $module;
        }
        ksort($byId, \SORT_STRING);
        foreach ($byId as $module) {
            foreach ($module->requires as $dependency) {
                if (!isset($byId[$dependency->value])) {
                    throw new InvalidManifest(\sprintf('Module %s requires %s, which is not installed.', $module->id->value, $dependency->value));
                }
            }
        }
        $this->modules = $byId;
    }

    public function has(ModuleId $id): bool
    {
        return isset($this->modules[$id->value]);
    }

    public function get(ModuleId $id): ModuleManifest
    {
        return $this->modules[$id->value] ?? throw new InvalidManifest(\sprintf('Module %s is not installed.', $id->value));
    }

    /**
     * @return list<ModuleManifest> sorted by id
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * Every module after the modules it requires. Modules in a cycle come
     * together, in id order; otherwise ties are broken by id.
     *
     * @return list<ModuleId>
     */
    public function dependencyOrder(): array
    {
        $components = $this->components();
        $componentOf = [];
        foreach ($components as $index => $component) {
            foreach ($component as $id) {
                $componentOf[$id] = $index;
            }
        }
        $waitingOn = array_fill_keys(array_keys($components), []);
        $neededBy = array_fill_keys(array_keys($components), []);
        foreach ($this->modules as $id => $module) {
            foreach ($module->requires as $dependency) {
                $from = $componentOf[$id];
                $to = $componentOf[$dependency->value];
                if ($from !== $to) {
                    $waitingOn[$from][$to] = true;
                    $neededBy[$to][$from] = true;
                }
            }
        }

        $order = [];
        $ready = array_keys(array_filter($waitingOn, static fn (array $on): bool => [] === $on));
        while ([] !== $ready) {
            usort($ready, static fn (int $a, int $b): int => strcmp($components[$a][0], $components[$b][0]));
            $next = array_shift($ready);
            foreach ($components[$next] as $id) {
                $order[] = $this->modules[$id]->id;
            }
            foreach (array_keys($neededBy[$next]) as $dependent) {
                unset($waitingOn[$dependent][$next]);
                if ([] === $waitingOn[$dependent]) {
                    $ready[] = $dependent;
                }
            }
        }

        return $order;
    }

    /**
     * @return list<GraphProblem> sorted by code
     */
    public function problems(): array
    {
        $problems = [];
        foreach ($this->components() as $component) {
            if (\count($component) > 1) {
                $problems[] = GraphProblem::cycle(array_map(fn (string $id): ModuleId => $this->modules[$id]->id, $this->cyclePath($component)));
            }
        }
        foreach ($this->modules as $module) {
            if (ModuleKind::Core !== $module->kind) {
                continue;
            }
            foreach ($module->requires as $dependency) {
                if (ModuleKind::Core !== $this->modules[$dependency->value]->kind) {
                    $problems[] = GraphProblem::coreRequiresModule($module->id, $dependency);
                }
            }
        }
        usort($problems, static fn (GraphProblem $a, GraphProblem $b): int => strcmp($a->code, $b->code));

        return $problems;
    }

    /**
     * Strongly connected components of the hard dependencies (Tarjan), each
     * sorted by id.
     *
     * @return list<non-empty-list<string>>
     */
    private function components(): array
    {
        $index = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $components = [];
        $counter = 0;

        $visit = function (string $id) use (&$visit, &$index, &$low, &$stack, &$onStack, &$components, &$counter): void {
            $index[$id] = $low[$id] = $counter++;
            $stack[] = $id;
            $onStack[$id] = true;
            foreach ($this->requiresOf($id) as $next) {
                if (!isset($index[$next])) {
                    $visit($next);
                    $low[$id] = min($low[$id], $low[$next]);
                } elseif (isset($onStack[$next])) {
                    $low[$id] = min($low[$id], $index[$next]);
                }
            }
            if ($low[$id] === $index[$id]) {
                $component = [];
                do {
                    $member = array_pop($stack);
                    unset($onStack[$member]);
                    $component[] = $member;
                } while ($member !== $id);
                sort($component, \SORT_STRING);
                $components[] = $component;
            }
        };
        foreach (array_keys($this->modules) as $id) {
            if (!isset($index[$id])) {
                $visit($id);
            }
        }

        return $components;
    }

    /**
     * The shortest cycle from the smallest id of a component along the edges
     * back to it, found breadth first with the smaller next id first, so the
     * code is stable and names only real edges.
     *
     * @param non-empty-list<string> $component sorted by id
     *
     * @return non-empty-list<string>
     */
    private function cyclePath(array $component): array
    {
        $start = $component[0];
        $previous = [$start => null];
        $queue = [$start];
        while ([] !== $queue) {
            $current = array_shift($queue);
            foreach ($this->requiresOf($current) as $next) {
                if ($next === $start) {
                    $tail = [];
                    for ($step = $current; $step !== $start && null !== $step; $step = $previous[$step]) {
                        array_unshift($tail, $step);
                    }

                    return [$start, ...$tail];
                }
                if (\in_array($next, $component, true) && !\array_key_exists($next, $previous)) {
                    $previous[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return $component;
    }

    /**
     * @return list<string> sorted by id
     */
    private function requiresOf(string $id): array
    {
        $ids = array_map(static fn (ModuleId $dependency): string => $dependency->value, $this->modules[$id]->requires);
        sort($ids, \SORT_STRING);

        return $ids;
    }
}
