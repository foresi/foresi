<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Module;

use Foresi\Attachment\AttachmentBundle;
use Foresi\Audit\AuditBundle;
use Foresi\Banking\Application\ProcessInboundMessage;
use Foresi\Banking\BankingBundle;
use Foresi\CashFlow\CashFlowBundle;
use Foresi\Country\CountryBundle;
use Foresi\Events\EventsBundle;
use Foresi\ExchangeRate\ExchangeRateBundle;
use Foresi\Identity\IdentityBundle;
use Foresi\Idoklad\Application\Message\ProcessIdokladJob;
use Foresi\Idoklad\IdokladBundle;
use Foresi\Import\Application\Message\ArchiveMissing;
use Foresi\Import\Application\Message\CheckImportChunk;
use Foresi\Import\Application\Message\ReadImportFile;
use Foresi\Import\Application\Message\RunImportChunk;
use Foresi\Import\Application\Message\UndoImportChunk;
use Foresi\Import\ImportBundle;
use Foresi\Instance\InstanceBundle;
use Foresi\Party\Application\RecheckRegistry;
use Foresi\Party\Infrastructure\Doctrine\VatIdType;
use Foresi\Party\PartyBundle;
use Foresi\Purchasing\PurchasingBundle;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Foresi\Shared\SharedBundle;
use Foresi\Tests\Shared\Fixtures\ModuleIntegration\Alpha\AlphaBundle;
use Foresi\Tests\Shared\Support\PrependsModuleBundles;
use Foresi\Timesheet\TimesheetBundle;
use Foresi\Ui\UiBundle;
use Foresi\Webhooks\WebhooksBundle;
use Foresi\Work\Application\SendNotificationMail;
use Foresi\Work\WorkBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\UX\StimulusBundle\DependencyInjection\StimulusExtension;

/**
 * Each module's bundle sits at the module's root, loads the module's own
 * services and prepends the Doctrine mapping of its entities and the
 * Messenger routing of its messages.
 */
final class ModuleBundleTest extends KernelTestCase
{
    use PrependsModuleBundles;

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
    #[DataProvider('installedPrepends')]
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
        // A module's other Doctrine configuration, such as a DQL function, is tested on its own.
        self::assertSame($expected, array_values(array_filter($builder->getExtensionConfig('doctrine'), static fn (array $config): bool => \is_array($config['orm'] ?? null) && isset($config['orm']['mappings']))));
    }

    public function testSharedAddsTheFunctionThatMatchesAJsonListAsText(): void
    {
        $builder = $this->builder('prod');
        new SharedBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertContains(['orm' => ['dql' => ['string_functions' => ['CAST_TEXT' => \Foresi\Shared\Infrastructure\Doctrine\CastTextFunction::class]]]], $builder->getExtensionConfig('doctrine'));
    }

    /**
     * The cases of the modules that are installed: the bundle of a module that is not cannot be loaded.
     *
     * @return iterable<string, array{ModuleBundle, string, list<string>, array<string, string>}>
     */
    public static function installedPrepends(): iterable
    {
        foreach (self::prepends() as $name => [$bundle, $env, $mappings, $routing]) {
            if (class_exists($bundle)) {
                yield $name => [new $bundle(), $env, $mappings, $routing];
            }
        }
    }

    /**
     * @return iterable<string, array{class-string<ModuleBundle>, string, list<string>, array<string, string>}>
     */
    private static function prepends(): iterable
    {
        yield 'shared in test' => [SharedBundle::class, 'test', ['SharedFixtures', 'Shared'], [\Foresi\Shared\Application\SystemMail::class => 'mail']];
        yield 'shared in prod' => [SharedBundle::class, 'prod', ['Shared'], [\Foresi\Shared\Application\SystemMail::class => 'mail']];
        yield 'audit in test' => [AuditBundle::class, 'test', ['AuditFixtures', 'Audit'], []];
        yield 'audit in prod' => [AuditBundle::class, 'prod', ['Audit'], []];
        yield 'instance' => [InstanceBundle::class, 'prod', ['Instance'], [
            \Foresi\Instance\Application\Schedule\RunScheduledJob::class => 'async',
            \Foresi\Instance\Application\Schedule\RunOperatorTask::class => 'async',
        ]];
        yield 'events' => [EventsBundle::class, 'prod', [], [
            \Foresi\Events\Application\RelayEvents::class => 'events',
            \Foresi\Events\Application\DeliverEvent::class => 'events',
        ]];
        yield 'webhooks' => [WebhooksBundle::class, 'prod', ['Webhooks'], [\Foresi\Webhooks\Application\SendWebhook::class => 'webhooks']];
        yield 'identity' => [IdentityBundle::class, 'prod', ['Identity'], []];
        yield 'attachment' => [AttachmentBundle::class, 'prod', ['Attachment'], []];
        yield 'ui' => [UiBundle::class, 'prod', [], []];
        yield 'banking' => [BankingBundle::class, 'prod', ['Banking'], [ProcessInboundMessage::class => 'async']];
        yield 'cash flow' => [CashFlowBundle::class, 'prod', ['CashFlow'], []];
        yield 'country' => [CountryBundle::class, 'prod', [], []];
        yield 'exchange rate' => [ExchangeRateBundle::class, 'prod', ['ExchangeRate'], []];
        yield 'idoklad' => [IdokladBundle::class, 'prod', ['Idoklad'], [ProcessIdokladJob::class => 'async']];
        yield 'import' => [ImportBundle::class, 'prod', ['Import'], [
            ReadImportFile::class => 'async',
            CheckImportChunk::class => 'async',
            RunImportChunk::class => 'async',
            ArchiveMissing::class => 'async',
            UndoImportChunk::class => 'async',
        ]];
        yield 'purchasing' => [PurchasingBundle::class, 'prod', ['Purchasing'], []];
        yield 'timesheet' => [TimesheetBundle::class, 'prod', ['Timesheet'], []];
        yield 'work' => [WorkBundle::class, 'prod', ['Work'], [SendNotificationMail::class => 'async']];
    }

    public function testPartyAddsItsTypeBeforeItsMappingAndRouting(): void
    {
        $builder = $this->builder('prod');
        new PartyBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertSame(['Party'], $this->mappings($builder));
        self::assertSame([
            'dbal' => ['types' => ['vat_id' => VatIdType::class]],
        ], $builder->getExtensionConfig('doctrine')[0]);
        self::assertSame([['messenger' => ['routing' => [RecheckRegistry::class => 'registry']]]], $builder->getExtensionConfig('framework'));
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

    public function testAnIntegrationRegistersItsTemplatesTranslationsAndControllersWhenItsModuleIsInstalled(): void
    {
        $builder = $this->builder('prod');
        $builder->registerExtension(new TwigExtension());
        $builder->registerExtension(new StimulusExtension());
        $bundle = new AlphaBundle(new ModuleGraph([$this->fixture('alpha', ['beta']), $this->fixture('beta')]));

        $bundle->prependExtension($this->configurator($builder, 'prod'), $builder);

        $beta = \dirname(__DIR__, 2).'/Fixtures/ModuleIntegration/Alpha/Integration/Beta';
        // Under the module's own namespace, so @Alpha/beta/hello.html.twig wherever the template lives.
        self::assertSame([['paths' => [$beta.'/templates' => 'Alpha']]], $builder->getExtensionConfig('twig'));
        self::assertSame([['controller_paths' => [$beta.'/assets/controllers']]], $builder->getExtensionConfig('stimulus'));
        self::assertSame([
            ['asset_mapper' => ['paths' => [$beta.'/assets/controllers' => '@foresi/alpha/beta']]],
            ['translator' => ['paths' => [$beta.'/translations']]],
        ], $builder->getExtensionConfig('framework'));
        self::assertTrue($bundle->loaded('beta', $builder));
    }

    public function testAnIntegrationRegistersNothingWhenItsModuleIsAbsent(): void
    {
        $builder = $this->builder('prod');
        $builder->registerExtension(new TwigExtension());
        $builder->registerExtension(new StimulusExtension());
        $bundle = new AlphaBundle(new ModuleGraph([$this->fixture('alpha', ['beta'])]));

        $bundle->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertSame([], $builder->getExtensionConfig('twig'));
        self::assertSame([], $builder->getExtensionConfig('stimulus'));
        self::assertSame([], $builder->getExtensionConfig('framework'));
        self::assertFalse($bundle->loaded('beta', $builder));
    }

    public function testAnInstalledModuleThatIsNotOptionalIsNoLoadedIntegration(): void
    {
        $bundle = new AlphaBundle(new ModuleGraph([$this->fixture('alpha', []), $this->fixture('beta')]));

        self::assertFalse($bundle->loaded('beta', $this->builder('prod')));
    }

    /**
     * @param list<string> $optional
     */
    private function fixture(string $id, array $optional = []): ModuleManifest
    {
        return new ModuleManifest(ModuleId::of($id), ModuleKind::Module, 'foresi/'.$id, 'Foresi\\'.ucfirst($id).'\\', [], array_map(ModuleId::of(...), $optional));
    }
}
