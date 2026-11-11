<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Module;

use App\Shared\Domain\Module\InvalidManifest;
use App\Shared\Domain\Module\ModuleKind;
use App\Shared\Domain\Module\ModuleManifest;
use App\Shared\Infrastructure\Module\InstalledModules;
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
        self::assertSame(
            ['attachment', 'audit', 'banking', 'country', 'exchange-rate', 'identity', 'idoklad', 'import', 'invoicing', 'party', 'purchasing', 'shared', 'tenancy', 'timesheet', 'ui', 'work'],
            array_map(static fn (ModuleManifest $module): string => $module->id->value, $this->modules()->graph()->all()),
        );
    }

    public function testKindsMatchTheSpec(): void
    {
        $kinds = [];
        foreach ($this->modules()->graph()->all() as $module) {
            $kinds[$module->kind->value][] = $module->id->value;
        }

        self::assertSame(['attachment', 'audit', 'identity', 'shared', 'tenancy'], $kinds[ModuleKind::Core->value] ?? []);
        self::assertSame(['ui'], $kinds[ModuleKind::Ui->value] ?? []);
        self::assertSame(
            ['banking', 'country', 'exchange-rate', 'idoklad', 'import', 'invoicing', 'party', 'purchasing', 'timesheet', 'work'],
            $kinds[ModuleKind::Module->value] ?? [],
        );
    }

    public function testNamespaceMatchesDirectory(): void
    {
        foreach ($this->modules()->graph()->all() as $module) {
            self::assertMatchesRegularExpression('/^App\\\\([A-Za-z]+)\\\\$/', $module->namespace);
            $directory = substr($module->namespace, 4, -1);
            self::assertDirectoryExists($this->projectDir().'/src/'.$directory);
            self::assertFileExists($this->projectDir().'/src/'.$directory.'/composer.json');
            self::assertSame('foresi/'.$module->id->value, $module->package);
        }
    }

    public function testReadsHardAndOptionalDependencies(): void
    {
        $manifest = InstalledModules::manifestFrom(self::composer(), ['foresi/shared', 'foresi/work', 'foresi/timesheet']);

        self::assertSame(['shared', 'work'], array_map(static fn (\App\Shared\Domain\Module\ModuleId $id): string => $id->value, $manifest->requires));
        self::assertSame(['invoicing'], array_map(static fn (\App\Shared\Domain\Module\ModuleId $id): string => $id->value, $manifest->optional));
        self::assertSame(ModuleKind::Module, $manifest->kind);
        self::assertSame('App\\Timesheet\\', $manifest->namespace);
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
        yield 'namespace outside App' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['namespace' => 'Vendor\\Timesheet\\']]])];
        yield 'optional not a list of ids' => [array_replace_recursive($composer, ['extra' => ['foresi' => ['optional' => 'invoicing']]])];
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
    private static function composer(): array
    {
        return [
            'name' => 'foresi/timesheet',
            'type' => 'foresi-module',
            'require' => ['foresi/shared' => '*', 'foresi/work' => '*', 'psr/clock' => '^1.0'],
            'extra' => ['foresi' => [
                'module' => 'timesheet',
                'kind' => 'module',
                'namespace' => 'App\\Timesheet\\',
                'optional' => ['invoicing'],
            ]],
        ];
    }

    private function modules(): InstalledModules
    {
        return new InstalledModules($this->projectDir());
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 4);
    }
}
