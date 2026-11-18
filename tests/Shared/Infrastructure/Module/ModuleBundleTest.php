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
use Foresi\Invoicing\Application\DeliverInvoiceSend;
use Foresi\Invoicing\Application\IssueRecurringTemplate;
use Foresi\Invoicing\Infrastructure\Messenger\WaitForLeaseMiddleware;
use Foresi\Invoicing\InvoicingBundle;
use Foresi\Party\Application\RecheckRegistry;
use Foresi\Party\Infrastructure\Doctrine\CastTextFunction;
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
use Foresi\Timesheet\TimesheetBundle;
use Foresi\Ui\UiBundle;
use Foresi\Webhooks\WebhooksBundle;
use Foresi\Work\Application\SendNotificationMail;
use Foresi\Work\WorkBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\UX\StimulusBundle\DependencyInjection\StimulusExtension;

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
        yield 'banking' => [new BankingBundle(), 'prod', ['Banking'], [ProcessInboundMessage::class => 'async']];
        yield 'cash flow' => [new CashFlowBundle(), 'prod', ['CashFlow'], []];
        yield 'country' => [new CountryBundle(), 'prod', [], []];
        yield 'exchange rate' => [new ExchangeRateBundle(), 'prod', ['ExchangeRate'], []];
        yield 'idoklad' => [new IdokladBundle(), 'prod', ['Idoklad'], [ProcessIdokladJob::class => 'async']];
        yield 'import' => [new ImportBundle(), 'prod', ['Import'], [
            ReadImportFile::class => 'async',
            CheckImportChunk::class => 'async',
            RunImportChunk::class => 'async',
            ArchiveMissing::class => 'async',
            UndoImportChunk::class => 'async',
        ]];
        yield 'purchasing' => [new PurchasingBundle(), 'prod', ['Purchasing'], []];
        yield 'timesheet' => [new TimesheetBundle(), 'prod', ['Timesheet'], []];
        yield 'work' => [new WorkBundle(), 'prod', ['Work'], [SendNotificationMail::class => 'async']];
    }

    public function testPartyAddsItsTypeAndFunctionBeforeItsMappingAndRouting(): void
    {
        $builder = $this->builder('prod');
        new PartyBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertSame(['Party'], $this->mappings($builder));
        self::assertSame([
            'dbal' => ['types' => ['vat_id' => VatIdType::class]],
            'orm' => ['dql' => ['string_functions' => ['CAST_TEXT' => CastTextFunction::class]]],
        ], $builder->getExtensionConfig('doctrine')[0]);
        self::assertSame([['messenger' => ['routing' => [RecheckRegistry::class => 'registry']]]], $builder->getExtensionConfig('framework'));
    }

    public function testInvoicingAddsItsMiddlewareBeforeItsRouting(): void
    {
        $builder = $this->builder('prod');
        new InvoicingBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertSame(['InvoicingContract', 'Invoicing'], $this->mappings($builder));
        // The frozen party of a document is published in the contract and stored as an embeddable.
        self::assertSame(['orm' => ['mappings' => ['InvoicingContract' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => $this->projectDir().'/src/Invoicing/Contract',
            'prefix' => 'Foresi\\Invoicing\\Contract',
            'alias' => 'InvoicingContract',
        ]]]], $builder->getExtensionConfig('doctrine')[0]);
        self::assertSame([
            ['messenger' => ['buses' => ['messenger.bus.default' => ['middleware' => [WaitForLeaseMiddleware::class]]]]],
            ['messenger' => ['routing' => [IssueRecurringTemplate::class => 'async', DeliverInvoiceSend::class => 'mail']]],
        ], $builder->getExtensionConfig('framework'));
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
        $builder->setParameter('kernel.project_dir', $this->projectDir());

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
