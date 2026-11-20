<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;
use Foresi\Shared\Domain\Module\ModuleGraph;

/**
 * Orders the migrations of all modules as one history (ADR 0034). Doctrine
 * sorts versions by their whole class name, so the migrations of one
 * namespace would all run before the next namespace's. This comparator
 * sorts by the timestamp in the class name (Version20261120000000) first,
 * then by the module's place in the dependency graph, so migrations of one
 * moment, such as the baselines, run a required module first and every
 * foreign key finds its table. A version of no installed module comes after
 * the modules' versions of its moment; the class name settles the rest.
 */
final class ModuleVersionComparator implements Comparator
{
    /**
     * @var array<string, int>|null the rank of each module namespace, such as "Foresi\Work\"
     */
    private ?array $ranks = null;

    public function __construct(private readonly ModuleGraph $modules)
    {
    }

    public function compare(Version $a, Version $b): int
    {
        return [$this->timestamp($a), $this->rank($a), (string) $a]
            <=> [$this->timestamp($b), $this->rank($b), (string) $b];
    }

    /**
     * The digits after "Version" in the short class name, padded so that a
     * longer timestamp never sorts before a shorter one; a name without them
     * sorts last.
     */
    private function timestamp(Version $version): string
    {
        $name = (string) $version;
        $short = substr($name, (int) strrpos($name, '\\') + (str_contains($name, '\\') ? 1 : 0));

        return 1 === preg_match('/^Version(\d+)/', $short, $match)
            ? str_pad($match[1], 20, '0', \STR_PAD_LEFT)
            : '~'.$short;
    }

    private function rank(Version $version): int
    {
        if (null === $this->ranks) {
            $this->ranks = [];
            foreach ($this->modules->dependencyOrder() as $rank => $id) {
                $this->ranks[$this->modules->get($id)->namespace] = $rank;
            }
        }
        $name = (string) $version;
        foreach ($this->ranks as $namespace => $rank) {
            if (str_starts_with($name, $namespace)) {
                return $rank;
            }
        }

        return \PHP_INT_MAX;
    }
}
