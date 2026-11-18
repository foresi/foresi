<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use Foresi\Attachment\AttachmentBundle;
use Foresi\Audit\AuditBundle;
use Foresi\Events\EventsBundle;
use Foresi\Identity\IdentityBundle;
use Foresi\Instance\InstanceBundle;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Foresi\Shared\SharedBundle;
use Foresi\Ui\UiBundle;
use Foresi\Webhooks\WebhooksBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Each module's bundle sits at the module's root, loads the module's own
 * services and prepends the Doctrine mapping of its entities and the
 * Messenger routing of its messages.
 */
final class ModuleBundleTest extends KernelTestCase
{
    public function testTheBundleOfEveryInstalledModuleIsRegistered(): void
    {
        $kernel = self::bootKernel();
        $bundles = $kernel->getBundles();

        foreach (new InstalledModules($kernel->getProjectDir())->graph()->all() as $module) {
            if (null === $module->bundle) {
                continue;
            }
            $bundle = array_find($bundles, static fn (object $bundle): bool => $bundle instanceof $module->bundle);
            self::assertInstanceOf(ModuleBundle::class, $bundle, $module->id->value);
            self::assertSame($kernel->getProjectDir().'/src/'.$bundle->module(), $bundle->getPath());
            self::assertFileExists($bundle->getPath().'/config/services.php');
        }
    }

    public function testTheExtensionIsNamedAfterTheModule(): void
    {
        self::assertSame('foresi_shared', new SharedBundle()->getContainerExtension()?->getAlias());
        self::assertSame('foresi_ui', new UiBundle()->getContainerExtension()?->getAlias());
    }

    public function testPrependsTheMappingOfTheEntitiesAndTheRoutingOfTheMessages(): void
    {
        $builder = $this->builder('prod');
        new WebhooksBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        $doctrine = $builder->getExtensionConfig('doctrine');
        self::assertSame([[
            'orm' => ['mappings' => ['Webhooks' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $this->projectDir().'/src/Webhooks/Domain',
                'prefix' => 'Foresi\\Webhooks\\Domain',
                'alias' => 'Webhooks',
            ]]],
        ]], $doctrine);
        self::assertSame([['messenger' => ['routing' => [\Foresi\Webhooks\Application\SendWebhook::class => 'webhooks']]]], $builder->getExtensionConfig('framework'));
    }

    /**
     * @param list<string>          $mappings the prepended Doctrine mappings, in order
     * @param array<string, string> $routing  the transport of each message
     */
    #[DataProvider('prepends')]
    public function testEachBundlePrependsItsMappingsAndRouting(ModuleBundle $bundle, string $env, array $mappings, array $routing): void
    {
        $builder = $this->builder($env);
        $bundle->prependExtension($this->configurator($builder, $env), $builder);

        self::assertSame($mappings, $this->mappings($builder));
        self::assertSame([] === $routing ? [] : [['messenger' => ['routing' => $routing]]], $builder->getExtensionConfig('framework'));
        $expected = [];
        foreach ($mappings as $name) {
            $tests = str_ends_with($name, 'Fixtures');
            $module = $tests ? substr($name, 0, -\strlen('Fixtures')) : $name;
            $expected[] = ['orm' => ['mappings' => [$name => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => $tests ? '%kernel.project_dir%/tests/'.$module.'/Fixtures' : self::projectDir().'/src/'.$module.'/Domain',
                'prefix' => $tests ? 'Foresi\\Tests\\'.$module.'\\Fixtures' : 'Foresi\\'.$module.'\\Domain',
                'alias' => $name,
            ]]]];
        }
        self::assertSame($expected, $builder->getExtensionConfig('doctrine'));
    }

    /**
     * @return iterable<string, array{ModuleBundle, string, list<string>, array<string, string>}>
     */
    public static function prepends(): iterable
    {
        yield 'shared in test' => [new SharedBundle(), 'test', ['SharedFixtures', 'Shared'], [\Foresi\Shared\Application\SystemMail::class => 'mail']];
        yield 'shared in prod' => [new SharedBundle(), 'prod', ['Shared'], [\Foresi\Shared\Application\SystemMail::class => 'mail']];
        yield 'audit in test' => [new AuditBundle(), 'test', ['AuditFixtures', 'Audit'], []];
        yield 'audit in prod' => [new AuditBundle(), 'prod', ['Audit'], []];
        yield 'instance' => [new InstanceBundle(), 'prod', ['Instance'], [
            \Foresi\Instance\Application\Schedule\RunScheduledJob::class => 'async',
            \Foresi\Instance\Application\Schedule\RunOperatorTask::class => 'async',
        ]];
        yield 'events' => [new EventsBundle(), 'prod', [], [
            \Foresi\Events\Application\RelayEvents::class => 'events',
            \Foresi\Events\Application\DeliverEvent::class => 'events',
        ]];
        yield 'webhooks' => [new WebhooksBundle(), 'prod', ['Webhooks'], [\Foresi\Webhooks\Application\SendWebhook::class => 'webhooks']];
        yield 'identity' => [new IdentityBundle(), 'prod', ['Identity'], []];
        yield 'attachment' => [new AttachmentBundle(), 'prod', ['Attachment'], []];
        yield 'ui' => [new UiBundle(), 'prod', [], []];
    }

    public function testAModuleWithoutEntitiesGetsNoMapping(): void
    {
        $builder = $this->builder('prod');
        new UiBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertSame([], $builder->getExtensionConfig('doctrine'));
        self::assertSame([], $builder->getExtensionConfig('framework'));
    }

    public function testTheFixturesOfTheTestsAreMappedInTheTestEnvironmentOnly(): void
    {
        $test = $this->builder('test');
        new SharedBundle()->prependExtension($this->configurator($test, 'test'), $test);
        $prod = $this->builder('prod');
        new SharedBundle()->prependExtension($this->configurator($prod, 'prod'), $prod);

        self::assertSame(['SharedFixtures', 'Shared'], $this->mappings($test));
        self::assertSame(['Shared'], $this->mappings($prod));
    }

    /**
     * @return list<string> the names of the prepended Doctrine mappings, in order
     */
    private function mappings(ContainerBuilder $builder): array
    {
        $names = [];
        foreach ($builder->getExtensionConfig('doctrine') as $config) {
            $orm = $config['orm'] ?? [];
            self::assertIsArray($orm);
            $mappings = $orm['mappings'] ?? [];
            self::assertIsArray($mappings);
            array_push($names, ...array_map(strval(...), array_keys($mappings)));
        }

        return $names;
    }

    private function builder(string $env): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', $env);

        return $builder;
    }

    private function configurator(ContainerBuilder $builder, string $env): ContainerConfigurator
    {
        $loader = new PhpFileLoader($builder, new FileLocator(), $env);
        $instanceof = [];

        return new ContainerConfigurator($builder, $loader, $instanceof, __FILE__, __FILE__, $env);
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 4);
    }
}
