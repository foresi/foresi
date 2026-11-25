<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use Foresi\Shared\Contract\ModuleApi;
use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleApiCheck;
use PHPUnit\Framework\TestCase;

/**
 * Every module requires a range of the module API, and the version this
 * Foresi provides, or the one an upgrade brings, must satisfy it (ADR 0044).
 */
final class ModuleApiCheckTest extends TestCase
{
    public function testTheInstalledModulesSuitThisForesi(): void
    {
        self::assertSame([], ModuleApiCheck::problems(new InstalledModules(\dirname(__DIR__, 4))->graph()));
    }

    public function testAModuleOfAnotherMajorIsNamedWithItsRange(): void
    {
        $graph = $this->graph(['acme-fleet' => '^2.0', 'acme-tools' => '^1.0 || ^2.0']);

        self::assertSame(['acme-fleet (acme/fleet) requires foresi/module-api ^2.0.'], ModuleApiCheck::problems($graph));
    }

    public function testAModuleRequiringNoModuleApiIsRefused(): void
    {
        self::assertSame(
            ['acme-fleet (acme/fleet) requires no foresi/module-api; add "foresi/module-api": "^'.implode('.', \array_slice(explode('.', ModuleApi::VERSION), 0, 2)).'" to "require" of its composer.json.'],
            ModuleApiCheck::problems($this->graph(['acme-fleet' => null])),
        );
    }

    public function testARangeThatIsNoneIsRefused(): void
    {
        self::assertSame(
            ['acme-fleet (acme/fleet) requires foresi/module-api "soon", which is not a version range.'],
            ModuleApiCheck::problems($this->graph(['acme-fleet' => 'soon'])),
        );
    }

    public function testAnUpgradeIsCheckedAgainstTheVersionItBrings(): void
    {
        $graph = $this->graph(['acme-fleet' => '^1.0', 'acme-tools' => '^1.0 || ^2.0']);

        self::assertSame(['acme-fleet (acme/fleet) requires foresi/module-api ^1.0.'], ModuleApiCheck::problems($graph, '2.0'));
        self::assertSame([], ModuleApiCheck::problems($graph, '1.4'));
    }

    public function testAVersionThatIsNoneIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ModuleApiCheck::problems($this->graph([]), 'next');
    }

    public function testTheContainerIsNotBuiltWithModulesThatDoNotSuit(): void
    {
        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessageIsOrContains('This Foresi provides the module API '.ModuleApi::VERSION.', which these modules are not written for: acme-fleet (acme/fleet) requires foresi/module-api ^2.0. acme-tools (acme/tools) requires no foresi/module-api;');

        ModuleApiCheck::assertCompatible($this->graph(['acme-fleet' => '^2.0', 'acme-tools' => null]));
    }

    public function testModulesThatSuitPass(): void
    {
        ModuleApiCheck::assertCompatible($this->graph(['acme-fleet' => '^1.0']));

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, ?string> $ranges the module API range of each module, by id
     */
    private function graph(array $ranges): ModuleGraph
    {
        $modules = [];
        foreach ($ranges as $id => $range) {
            $name = substr($id, \strlen('acme-'));
            $modules[] = new ModuleManifest(ModuleId::of($id), ModuleKind::Module, 'acme/'.$name, 'Acme\\'.ucfirst($name).'\\', [], [], moduleApi: $range);
        }

        return new ModuleGraph($modules);
    }
}
