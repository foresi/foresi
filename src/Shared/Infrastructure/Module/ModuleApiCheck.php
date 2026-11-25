<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Module;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Foresi\Shared\Contract\ModuleApi;
use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleGraph;

/**
 * Whether the installed modules are written for a version of the module API
 * (ADR 0044): each module requires a range of foresi/module-api, and the
 * version must satisfy it. Composer refuses such a set already; this catches a
 * vendor directory Composer did not produce, such as a copied release, and
 * tells before an upgrade which modules a new version would leave behind.
 */
final readonly class ModuleApiCheck
{
    /**
     * One line for each module the version does not suit, in id order.
     *
     * @return list<string>
     *
     * @throws \UnexpectedValueException when $version is not a version
     */
    public static function problems(ModuleGraph $graph, string $version = ModuleApi::VERSION): array
    {
        new VersionParser()->normalize($version);
        $problems = [];
        foreach ($graph->all() as $module) {
            $range = $module->moduleApi;
            if (null === $range) {
                $problems[] = \sprintf('%s (%s) requires no %s; add "%s": "^%s" to "require" of its composer.json.', $module->id->value, $module->package, ModuleApi::PACKAGE, ModuleApi::PACKAGE, self::minor(ModuleApi::VERSION));
                continue;
            }
            try {
                $suits = Semver::satisfies($version, $range);
            } catch (\UnexpectedValueException) {
                $problems[] = \sprintf('%s (%s) requires %s "%s", which is not a version range.', $module->id->value, $module->package, ModuleApi::PACKAGE, $range);
                continue;
            }
            if (!$suits) {
                $problems[] = \sprintf('%s (%s) requires %s %s.', $module->id->value, $module->package, ModuleApi::PACKAGE, $range);
            }
        }

        return $problems;
    }

    /**
     * Stops the container build when a module does not suit this Foresi's module API.
     *
     * @throws InvalidManifest naming each module that does not suit it and its range
     */
    public static function assertCompatible(ModuleGraph $graph): void
    {
        $problems = self::problems($graph);
        if ([] !== $problems) {
            throw new InvalidManifest(\sprintf('This Foresi provides the module API %s, which these modules are not written for: %s Update them, or remove them with composer remove.', ModuleApi::VERSION, implode(' ', $problems)));
        }
    }

    private static function minor(string $version): string
    {
        return implode('.', \array_slice(explode('.', $version), 0, 2));
    }
}
