<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Timesheet\TimesheetBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The modules are the foresi-module packages Composer installed, each read
 * from the composer.json in its directory.
 */
final class InstalledModulesTest extends TestCase
{
    public function testFindsEveryModuleDirectory(): void
    {
        // The module packages under src/: all of them in the repository, the installed ones in an application.
        $directories = [];
        foreach ((array) glob($this->projectDir().'/src/*/composer.json') as $file) {
            $composer = json_decode((string) file_get_contents((string) $file), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($composer);
            if ('foresi-module' === ($composer['type'] ?? null) && \is_string($composer['name'] ?? null)) {
                $directories[] = substr($composer['name'], \strlen('foresi/'));
            }
        }
        sort($directories);

        self::assertContains('shared', $directories);
        self::assertSame($directories, $this->installed());
    }

    public function testKindsMatchTheSpec(): void
    {
        $kinds = [];
        foreach ($this->modules()->graph()->all() as $module) {
            $kinds[$module->kind->value][] = $module->id->value;
        }
        $installed = $this->installed();

        self::assertSame(['attachment', 'audit', 'events', 'identity', 'instance', 'party', 'shared', 'webhooks'], $kinds[ModuleKind::Core->value] ?? []);
        self::assertSame(['ui'], $kinds[ModuleKind::Ui->value] ?? []);
        self::assertSame(
            array_values(array_intersect(['activities', 'ai-reader', 'banking', 'calendar', 'cash-flow', 'catalog', 'country', 'documents', 'exchange-rate', 'idoklad', 'import', 'invoicing', 'mail', 'margin', 'purchasing', 'sales', 'taxes-and-contributions', 'timesheet', 'vat', 'work'], $installed)),
            $kinds[ModuleKind::Module->value] ?? [],
        );
    }

    public function testNamespaceMatchesDirectory(): void
    {
        foreach ($this->modules()->graph()->all() as $module) {
            self::assertMatchesRegularExpression('/^Foresi\\\\([A-Za-z]+)\\\\$/', $module->namespace);
            $directory = substr($module->namespace, \strlen('Foresi\\'), -1);
            self::assertDirectoryExists($this->projectDir().'/src/'.$directory);
            self::assertFileExists($this->projectDir().'/src/'.$directory.'/composer.json');
            self::assertSame('foresi/'.$module->id->value, $module->package);
        }
    }

    public function testReadsHardAndOptionalDependencies(): void
    {
        $manifest = InstalledModules::manifestFrom(self::composer(), ['foresi/shared' => 'shared', 'foresi/work' => 'work', 'foresi/timesheet' => 'timesheet']);

        self::assertSame(['shared', 'work'], array_map(static fn (\Foresi\Shared\Domain\Module\ModuleId $id): string => $id->value, $manifest->requires));
        self::assertSame(['invoicing'], array_map(static fn (\Foresi\Shared\Domain\Module\ModuleId $id): string => $id->value, $manifest->optional));
        self::assertSame(ModuleKind::Module, $manifest->kind);
        self::assertSame('Foresi\\Timesheet\\', $manifest->namespace);
    }

    public function testReadsTheBundle(): void
    {
        self::assertSame(TimesheetBundle::class, InstalledModules::manifestFrom(self::composer(), [])->bundle);
    }

    public function testEveryModuleNamesItsBundle(): void
    {
        foreach ($this->modules()->graph()->all() as $module) {
            $directory = substr($module->namespace, \strlen('Foresi\\'), -1);
            self::assertSame($module->namespace.$directory.'Bundle', $module->bundle, $module->id->value);
        }
    }

    public function testTheInstalledModulesDeclareTheirApiResources(): void
    {
        $graph = new InstalledModules(\dirname(__DIR__, 4))->graph();

        // The resources of each module that declares some, as many as are installed.
        $resources = ['activities' => ['activities'], 'calendar' => ['calendar'], 'cash-flow' => ['cashflow'], 'catalog' => ['catalog'], 'documents' => ['documents'], 'events' => ['events'], 'exchange-rate' => ['exchange_rates'], 'invoicing' => ['invoices', 'payments'], 'mail' => ['mail_addresses'], 'margin' => ['margin'], 'party' => ['contacts'], 'purchasing' => ['received_documents'], 'sales' => ['sales'], 'shared' => ['views'], 'taxes-and-contributions' => ['taxes'], 'vat' => ['vat'], 'webhooks' => ['webhooks'], 'work' => ['projects', 'tasks', 'time_entries']];
        $expected = array_merge(...array_values(array_intersect_key($resources, array_flip($this->installed()))));
        sort($expected);
        self::assertSame($expected, $graph->resources());
        self::assertSame(['contacts'], $graph->get(\Foresi\Shared\Domain\Module\ModuleId::of('party'))->scopes);
        self::assertSame([], InstalledModules::manifestFrom(self::composer(), [])->scopes);
        // A guest may change tasks and read projects, and nothing else (ADR 0041).
        $guest = ['work' => ['projects:read', 'tasks:write']];
        $expected = array_merge([], ...array_values(array_intersect_key($guest, array_flip($this->installed()))));
        self::assertSame($expected, $graph->guestScopes());
    }

    public function testAResourceOpenToGuestsIsWrittenWithHowFar(): void
    {
        $composer = array_replace_recursive(self::composer(), ['extra' => ['foresi' => ['scopes' => ['hours', ['resource' => 'reports', 'guest' => 'read'], ['resource' => 'notes', 'guest' => 'write']]]]]);

        $manifest = InstalledModules::manifestFrom($composer, []);

        self::assertSame(['hours', 'reports', 'notes'], $manifest->scopes);
        self::assertSame(['reports' => false, 'notes' => true], $manifest->guestScopes);
    }

    public function testExtraMissingIsRefused(): void
    {
        $composer = self::composer();
        unset($composer['extra']);

        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessageIsOrContains('foresi/timesheet');

        InstalledModules::manifestFrom($composer, []);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function brokenManifests(): iterable
    {
        $composer = self::composer();
        yield 'unknown kind' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['kind' => 'plugin']]])];
        yield 'package not named after the id' => [array_replace_recursive($composer, ['name' => 'foresi/time'])];
        yield 'a Foresi package outside the Foresi namespace' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['namespace' => 'Vendor\\Timesheet\\']]])];
        yield 'a Foresi namespace below a module' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['namespace' => 'Foresi\\Timesheet\\Hours\\']]])];
        yield 'a namespace the autoload does not declare' => [array_replace($composer, ['autoload' => ['psr-4' => ['Foresi\\Time\\' => '']]])];
        yield 'an id the namespace does not give' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['module' => 'hours']], 'name' => 'foresi/hours'])];
        yield 'a package name that is not one' => [array_replace($composer, ['name' => 'Foresi Timesheet'])];
        yield 'the module API without a range' => [array_replace_recursive($composer, ['require' => ['foresi/module-api' => ['^1.0']]])];
        yield 'another vendor in the Foresi namespace' => [self::acme(['extra' => ['foresi' => ['namespace' => 'Foresi\\Garage\\', 'module' => 'garage', 'bundle' => 'Foresi\\Garage\\GarageBundle']], 'autoload' => ['psr-4' => ['Foresi\\Garage\\' => '']]])];
        yield 'another vendor with an id its namespace does not give' => [self::acme(['extra' => ['foresi' => ['module' => 'garage']]])];
        yield 'another vendor with a bundle not named after the id' => [self::acme(['extra' => ['foresi' => ['bundle' => 'Acme\\Garage\\FleetBundle']]])];
        yield 'a namespace of one segment' => [self::acme(['extra' => ['foresi' => ['namespace' => 'Acme\\', 'module' => 'acme', 'bundle' => 'Acme\\AcmeBundle']], 'autoload' => ['psr-4' => ['Acme\\' => '']]])];
        yield 'scopes not a list' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['scopes' => 'hours']]])];
        yield 'a guest scope without how far' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['scopes' => [['resource' => 'hours']]]]])];
        yield 'a guest scope going further than writing' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['scopes' => [['resource' => 'hours', 'guest' => 'admin']]]]])];
        yield 'a guest scope that is not a word' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['scopes' => [['resource' => 'Hours', 'guest' => 'read']]]]])];
        yield 'a scope that is not a word' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['scopes' => ['Hours:read']]]])];
        yield 'optional not a list of ids' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['optional' => 'invoicing']]])];
        yield 'bundle missing' => [self::composer(bundle: false)];
        yield 'bundle not a class name' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['bundle' => ['TimesheetBundle']]]])];
        yield 'bundle outside the namespace' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['bundle' => 'Foresi\\Work\\TimesheetBundle']]])];
        yield 'bundle below the root of the namespace' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['bundle' => 'Foresi\\Timesheet\\Infrastructure\\TimesheetBundle']]])];
        yield 'bundle without the suffix' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['bundle' => 'Foresi\\Timesheet\\Timesheet']]])];
    }

    /**
     * @param array<mixed> $composer
     */
    #[DataProvider('brokenManifests')]
    public function testBrokenManifestIsRefused(array $composer): void
    {
        $this->expectException(InvalidManifest::class);

        InstalledModules::manifestFrom($composer, []);
    }

    public function testAModuleOfAnotherVendorHasTheIdOfItsNamespace(): void
    {
        $manifest = InstalledModules::manifestFrom(self::acme(), ['foresi/shared' => 'shared', 'foresi/party' => 'party', 'acme/garage' => 'acme-garage']);

        self::assertSame('acme-garage', $manifest->id->value);
        self::assertSame('acme/garage', $manifest->package);
        self::assertSame('Acme\\Garage\\', $manifest->namespace);
        self::assertSame('Acme\\Garage\\AcmeGarageBundle', $manifest->bundle);
        self::assertSame(['shared', 'party'], array_map(static fn (\Foresi\Shared\Domain\Module\ModuleId $id): string => $id->value, $manifest->requires));
        self::assertSame('^1.0', $manifest->moduleApi);
    }

    public function testARequiredModuleIsFoundByItsPackageWhateverItsVendor(): void
    {
        $composer = array_replace_recursive(self::composer(), ['require' => ['acme/garage' => '*']]);

        $manifest = InstalledModules::manifestFrom($composer, ['acme/garage' => 'acme-garage', 'foresi/work' => 'work']);

        self::assertSame(['work', 'acme-garage'], array_map(static fn (\Foresi\Shared\Domain\Module\ModuleId $id): string => $id->value, $manifest->requires));
    }

    public function testAModuleWithoutTheModuleApiHasNoRange(): void
    {
        self::assertNull(InstalledModules::manifestFrom(self::composer(), [])->moduleApi);
    }

    public function testEveryModuleRequiresTheModuleApi(): void
    {
        foreach ($this->modules()->graph()->all() as $module) {
            self::assertSame('^1.0', $module->moduleApi, $module->id->value);
        }
    }

    public function testAcceptedProblemsComeFromTheRootComposerJson(): void
    {
        $directory = sys_get_temp_dir().'/foresi-modules-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/composer.json', json_encode(['extra' => ['foresi' => ['accepted-problems' => ['cycle:identity>party>identity']]]], \JSON_THROW_ON_ERROR));

        try {
            self::assertSame(['cycle:identity>party>identity'], new InstalledModules($directory)->acceptedProblems());
        } finally {
            unlink($directory.'/composer.json');
            rmdir($directory);
        }
    }

    public function testNoAcceptedProblemsWhenTheListIsMissing(): void
    {
        $directory = sys_get_temp_dir().'/foresi-modules-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/composer.json', '{"name": "acme/app"}');

        try {
            self::assertSame([], new InstalledModules($directory)->acceptedProblems());
        } finally {
            unlink($directory.'/composer.json');
            rmdir($directory);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(bool $bundle = true): array
    {
        return [
            'name' => 'foresi/timesheet',
            'type' => 'foresi-module',
            'require' => ['foresi/shared' => '*', 'foresi/work' => '*', 'psr/clock' => '^1.0'],
            'autoload' => ['psr-4' => ['Foresi\\Timesheet\\' => '']],
            'extra' => ['foresi' => [
                'module' => 'timesheet',
                'kind' => 'module',
                'namespace' => 'Foresi\\Timesheet\\',
                'optional' => ['invoicing'],
            ] + ($bundle ? ['bundle' => TimesheetBundle::class] : [])],
        ];
    }

    /**
     * The manifest of a module of another vendor, with $changes merged in.
     *
     * @param array<mixed> $changes
     *
     * @return array<mixed>
     */
    private static function acme(array $changes = []): array
    {
        return array_replace_recursive([
            'name' => 'acme/garage',
            'type' => 'foresi-module',
            'require' => ['foresi/module-api' => '^1.0', 'foresi/shared' => '*', 'foresi/party' => '*'],
            'autoload' => ['psr-4' => ['Acme\\Garage\\' => '']],
            'extra' => ['foresi' => [
                'module' => 'acme-garage',
                'kind' => 'module',
                'namespace' => 'Acme\\Garage\\',
                'bundle' => 'Acme\\Garage\\AcmeGarageBundle',
            ]],
        ], $changes);
    }

    private function modules(): InstalledModules
    {
        return new InstalledModules($this->projectDir());
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * @return list<string> the ids of the installed modules, sorted
     */
    private function installed(): array
    {
        return array_map(static fn (ModuleManifest $module): string => $module->id->value, $this->modules()->graph()->all());
    }
}
