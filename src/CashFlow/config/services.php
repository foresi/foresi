<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\CashFlow\Application\ForecastReads;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\Pairing;
use Foresi\CashFlow\Application\PairingReads;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Application\RuleMovements;
use Foresi\CashFlow\Contract\DocumentPairer;
use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\MovementFinder;
use Foresi\CashFlow\Contract\PairableDocumentSource;
use Foresi\CashFlow\Contract\PlannedItemCounterparties;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedPairings;
use Foresi\CashFlow\Infrastructure\Directory\PlannedItemsDirectory;
use Foresi\CashFlow\Infrastructure\Screen\DocumentPairingsScreen;
use Foresi\Tests\CashFlow\Support\ExamplePlannedItemSection;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the CashFlow module, loaded by Foresi\CashFlow\CashFlowBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\CashFlow\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../CashFlowBundle.php']);

    // Modules that expect, hold or record money plug in through the contracts of Foresi\CashFlow\Contract.
    $services->set(Forecasts::class)
        ->arg('$sources', tagged_iterator('app.cash_flow_source'))
        ->arg('$balanceSources', tagged_iterator('app.cash_flow_balance_source'))
        ->arg('$actualSources', tagged_iterator('app.cash_flow_actual_source'));
    // Other modules read the forecast's flows through Foresi\CashFlow\Contract\ForecastReader, asked the same way.
    $services->set(ForecastReads::class)
        ->arg('$sources', tagged_iterator('app.cash_flow_source'))
        ->arg('$actualSources', tagged_iterator('app.cash_flow_actual_source'));
    $services->alias(ForecastReader::class, ForecastReads::class)->public();
    // Other modules find the bank movements that meet their match rules through Foresi\CashFlow\Contract\MovementFinder.
    $services->set(RuleMovements::class)
        ->arg('$actualSources', tagged_iterator('app.cash_flow_actual_source'));
    $services->alias(MovementFinder::class, RuleMovements::class)->public();
    // Other modules hand documents to Cash flow to pair with planned occurrences, read the pairings and look planned items up.
    $services->set(Pairing::class)
        ->arg('$sources', tagged_iterator(PairableDocumentSource::TAG));
    $services->alias(DocumentPairer::class, Pairing::class)->public();
    $services->alias(PlannedPairings::class, PairingReads::class)->public();
    $services->alias(PlannedItemCounterparties::class, PlannedItemWrites::class)->public();
    $services->alias(DocumentPairings::class, DocumentPairingsScreen::class)->public();
    $services->alias(PlannedItemDirectory::class, PlannedItemsDirectory::class)->public();
    if ('test' === $container->env()) {
        // Lets a test add a module's section to the page of a planned item.
        $services->set(ExamplePlannedItemSection::class);
    }
};
