<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Banking\Application\BankingCountries;
use Foresi\Banking\Application\BankMessageParsers;
use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\InboundProcessing;
use Foresi\Banking\Application\IntakeStatement;
use Foresi\Banking\Application\Matcher;
use Foresi\Banking\Application\Ownership;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\PendingChanges;
use Foresi\Banking\Application\ReceivedStatements;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\StatementFormats;
use Foresi\Banking\Application\UnpairMovement;
use Foresi\Banking\Contract\BankingCountry;
use Foresi\Banking\Contract\BankMessageParser;
use Foresi\Banking\Contract\StatementFormat;
use Foresi\Banking\Contract\StatementIntake;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineBankMovements;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineFirmPairings;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineInboundMessages;
use Foresi\Banking\Infrastructure\Doctrine\DoctrinePendingChanges;
use Foresi\Banking\Infrastructure\Doctrine\DoctrineReceivedStatements;
use Foresi\Banking\Infrastructure\IdentityOwnership;
use Foresi\Banking\Infrastructure\Messenger\MessengerInboundProcessing;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Banking module, loaded by Foresi\Banking\BankingBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Banking\\', '../')
        ->exclude(['../config/', '../Integration/', '../migrations/', '../BankingBundle.php']);

    // A country module plugs into banking with the parsers of its banks' mail.

    $services->set(BankMessageParsers::class)
        ->arg('$parsers', tagged_iterator(BankMessageParser::TAG));
    // A country module plugs into banking with how its accounts are written and its time zone.
    $services->set(BankingCountries::class)
        ->arg('$countries', tagged_iterator(BankingCountry::TAG));
    // A country or format module adds the formats of statement files.
    $services->set(StatementFormats::class)
        ->arg('$formats', tagged_iterator(StatementFormat::TAG));
    // The one way movements come in, for every channel; a connector module calls it too.
    $services->alias(StatementIntake::class, IntakeStatement::class);

    // A firm's stored switches, or the defaults while it has none.
    $services->alias(FirmPairingRules::class, DoctrineFirmPairings::class);
    $services->alias(BankMovements::class, DoctrineBankMovements::class);
    // Without Invoicing (Integration/Invoicing/), NoPaymentPairing and NoCandidates leave every movement unmatched.
    $services->alias(InboundMessages::class, DoctrineInboundMessages::class);
    $services->alias(Ownership::class, IdentityOwnership::class);
    $services->alias(InboundProcessing::class, MessengerInboundProcessing::class);
    $services->alias(PendingChanges::class, DoctrinePendingChanges::class);
    $services->alias(ReceivedStatements::class, DoctrineReceivedStatements::class);
    if ('test' === $container->env()) {
        // Public for the banking tests and the worker of the pairing race, which drive them directly.
        $services->alias(BankMovements::class, DoctrineBankMovements::class)->public();
        $services->alias(ReceivedStatements::class, DoctrineReceivedStatements::class)->public();
        $services->alias(StatementIntake::class, IntakeStatement::class)->public();
        $services->set(StatementFormats::class)->arg('$formats', tagged_iterator(StatementFormat::TAG))->public();
        foreach ([RecordMovement::class, PairMovement::class, UnpairMovement::class, Matcher::class] as $service) {
            $services->set($service)->public();
        }
    }

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.record_movement', RecordMovement::class)->public();
        $services->alias('e2e.pair_movement', PairMovement::class)->public();
        $services->alias('e2e.statement_intake', StatementIntake::class)->public();
        $services->alias('e2e.generate_address', GenerateAddress::class)->public();
    }
};
