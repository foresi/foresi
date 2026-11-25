<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Domain\Module;

use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Domain\Module\ModuleNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A module's namespace gives its id and names (ADR 0044): Foresi's own keep
 * theirs, any other vendor's start with the vendor.
 */
final class ModuleNamespaceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string, bool}>
     */
    public static function namespaces(): iterable
    {
        yield 'Foresi\'s own' => ['Foresi\\CashFlow\\', 'cash-flow', \Foresi\CashFlow\CashFlowBundle::class, 'foresi_cash_flow', '@foresi/cash-flow', true];
        yield 'Foresi\'s own of several words' => ['Foresi\\TaxesAndContributions\\', 'taxes-and-contributions', \Foresi\TaxesAndContributions\TaxesAndContributionsBundle::class, 'foresi_taxes_and_contributions', '@foresi/taxes-and-contributions', true];
        yield 'another vendor\'s' => ['Acme\\Garage\\', 'acme-garage', 'Acme\\Garage\\AcmeGarageBundle', 'acme_garage', '@acme-garage', false];
        yield 'another vendor\'s of three segments' => ['Acme\\Erp\\GarageCare\\', 'acme-erp-garage-care', 'Acme\\Erp\\GarageCare\\AcmeErpGarageCareBundle', 'acme_erp_garage_care', '@acme-erp-garage-care', false];
        yield 'an acronym' => ['ACMECorp\\Garage\\', 'acme-corp-garage', 'ACMECorp\\Garage\\AcmeCorpGarageBundle', 'acme_corp_garage', '@acme-corp-garage', false];
    }

    #[DataProvider('namespaces')]
    public function testTheNamespaceGivesTheNames(string $namespace, string $id, string $bundle, string $alias, string $assets, bool $ownPackage): void
    {
        $names = ModuleNamespace::of($namespace);

        self::assertSame($id, $names->id()->value);
        self::assertSame($bundle, $names->bundle());
        self::assertSame($alias, $names->alias());
        self::assertSame($assets, $names->assets());
        self::assertSame($ownPackage, $names->ofOwnPackage());
    }

    public function testTheNamespaceOfABundleIsWhereItSits(): void
    {
        self::assertSame('Acme\\Garage\\', ModuleNamespace::ofBundle('Acme\\Garage\\AcmeGarageBundle')->value);
    }

    public function testAFixtureBelowForesiIsOneOfForesisOwnButNotOfAPackage(): void
    {
        $names = ModuleNamespace::of('Foresi\\Tests\\Fixtures\\Alpha\\');

        self::assertTrue($names->own());
        self::assertFalse($names->ofOwnPackage());
        self::assertSame('alpha', $names->id()->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notNamespaces(): iterable
    {
        yield 'one segment' => ['Acme\\'];
        yield 'no trailing separator' => ['Acme\\Fleet'];
        yield 'lowercase' => ['acme\\fleet\\'];
        yield 'empty' => [''];
    }

    #[DataProvider('notNamespaces')]
    public function testAnythingElseIsRefused(string $namespace): void
    {
        $this->expectException(InvalidManifest::class);

        ModuleNamespace::of($namespace);
    }

    public function testToolsOfForesisPackagesStartWithForesi(): void
    {
        self::assertSame('foresi_', $this->manifest('cash-flow', 'foresi/cash-flow', 'Foresi\\CashFlow\\')->toolPrefix());
        self::assertSame('acme_garage_', $this->manifest('acme-garage', 'acme/garage', 'Acme\\Garage\\')->toolPrefix());
    }

    public function testAModuleOwnsTheClassesOfItsNamespace(): void
    {
        $manifest = $this->manifest('acme-garage', 'acme/garage', 'Acme\\Garage\\');

        self::assertTrue($manifest->owns('Acme\\Garage\\Application\\AddVehicleInput'));
        self::assertFalse($manifest->owns('Acme\\GarageCare\\Application\\AddVehicleInput'));
    }

    private function manifest(string $id, string $package, string $namespace): ModuleManifest
    {
        return new ModuleManifest(ModuleId::of($id), ModuleKind::Module, $package, $namespace, [], []);
    }
}
