<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use Foresi\Shared\Domain\Module\InvalidManifest;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleIntegration;
use Foresi\Shared\Infrastructure\Module\ModuleIntegrations;
use Foresi\Tests\Shared\Fixtures\ModuleIntegration\Alpha\Integration\Beta\BetaGreeting;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * A module's integration code for an optional dependency is registered when
 * that dependency is installed and left out when it is absent. The fixture
 * module Alpha has src-like code in tests/Shared/Fixtures/ModuleIntegration.
 */
final class ModuleIntegrationsTest extends TestCase
{
    private const string ROOT = 'Foresi\\Tests\\Shared\\Fixtures\\ModuleIntegration\\';

    public function testTheIntegrationIsLoadedWhenTheOptionalDependencyIsInstalled(): void
    {
        $graph = new ModuleGraph([$this->alpha(optional: ['beta']), $this->module('beta')]);
        $builder = new ContainerBuilder();

        $integrations = $this->integrations()->register($graph, $this->configurator($builder));

        self::assertCount(1, $integrations);
        self::assertSame('alpha', $integrations[0]->module->value);
        self::assertSame('beta', $integrations[0]->uses->value);
        self::assertSame(self::ROOT.'Alpha\\Integration\\Beta\\', $integrations[0]->namespace);
        self::assertTrue($integrations[0]->loaded);
        self::assertTrue($builder->hasDefinition(BetaGreeting::class));
        self::assertTrue($builder->getDefinition(BetaGreeting::class)->isAutowired());
        self::assertTrue($builder->getDefinition(BetaGreeting::class)->isAutoconfigured());
    }

    public function testTheIntegrationIsLeftOutWhenTheOptionalDependencyIsAbsent(): void
    {
        $graph = new ModuleGraph([$this->alpha(optional: ['beta'])]);
        $builder = new ContainerBuilder();

        $integrations = $this->integrations()->register($graph, $this->configurator($builder));

        self::assertCount(1, $integrations);
        self::assertFalse($integrations[0]->loaded);
        self::assertFalse($builder->hasDefinition(BetaGreeting::class));
    }

    public function testIntegrationCodeForAnUndeclaredModuleIsRefused(): void
    {
        $graph = new ModuleGraph([$this->alpha(optional: []), $this->module('beta')]);

        $this->expectException(InvalidManifest::class);
        $this->expectExceptionMessageIsOrContains('Module alpha has integration code in Alpha/Integration/Beta but lists no module Beta in extra.foresi.optional');

        $this->integrations()->of($graph);
    }

    public function testAModuleWithoutIntegrationCodeHasNone(): void
    {
        self::assertSame([], $this->integrations()->of(new ModuleGraph([$this->module('beta', optional: ['alpha'])])));
    }

    public function testTheProjectDeclaresEveryIntegration(): void
    {
        $root = \dirname(__DIR__, 4);
        $graph = new InstalledModules($root)->graph();

        // Refuses an integration directory that the module's manifest does not declare.
        self::assertContainsOnlyInstancesOf(ModuleIntegration::class, new ModuleIntegrations($root.'/src')->of($graph));
    }

    public function testWithoutASourceDirectoryEachModuleIsReadWhereComposerInstalledIt(): void
    {
        $root = \dirname(__DIR__, 4);
        $graph = new InstalledModules($root)->graph();

        // From Packagist a module sits in vendor/foresi/<package>, not in src/<Name>;
        // here the path repositories point Composer back at src/, so both agree.
        $fromComposer = new ModuleIntegrations()->of($graph);
        self::assertNotSame([], $fromComposer);
        self::assertEquals(new ModuleIntegrations($root.'/src')->of($graph), $fromComposer);
        foreach ($fromComposer as $integration) {
            self::assertSame(InstalledModules::directoryOf($graph->get($integration->module)).'/Integration/'.basename($integration->directory), $integration->directory);
        }
    }

    private function integrations(): ModuleIntegrations
    {
        return new ModuleIntegrations(\dirname(__DIR__, 2).'/Fixtures/ModuleIntegration', self::ROOT);
    }

    private function configurator(ContainerBuilder $builder): ContainerConfigurator
    {
        $instanceof = [];

        return new ContainerConfigurator($builder, new PhpFileLoader($builder, new FileLocator(__DIR__)), $instanceof, __FILE__, __FILE__);
    }

    /**
     * @param list<string> $optional
     */
    private function alpha(array $optional): ModuleManifest
    {
        return $this->module('alpha', $optional);
    }

    /**
     * @param list<string> $optional
     */
    private function module(string $id, array $optional = []): ModuleManifest
    {
        return new ModuleManifest(
            ModuleId::of($id),
            ModuleKind::Module,
            'foresi/'.$id,
            'Foresi\\'.ucfirst($id).'\\',
            [],
            array_map(ModuleId::of(...), $optional),
        );
    }
}
