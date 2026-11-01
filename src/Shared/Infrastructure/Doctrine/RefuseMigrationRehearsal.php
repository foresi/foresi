<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Migrations\Event\MigrationsEventArgs;
use Doctrine\Migrations\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stops --dry-run and --write-sql before anything runs when the plan holds a
 * RunsOnlyForReal migration. --dry-run reaches the migrator; --write-sql alone
 * does not, as Doctrine then migrates for real and writes a file that holds
 * none of this work, so it is read from the command.
 */
#[AsDoctrineListener(event: Events::onMigrationsMigrating)]
final class RefuseMigrationRehearsal
{
    private bool $writesSql = false;

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();
        $this->writesSql = $input->hasOption('write-sql') && false !== $input->getOption('write-sql');
    }

    public function onMigrationsMigrating(MigrationsEventArgs $args): void
    {
        if (!$args->getMigratorConfiguration()->isDryRun() && !$this->writesSql) {
            return;
        }
        foreach ($args->getPlan()->getItems() as $plan) {
            if ($plan->getMigration() instanceof RunsOnlyForReal) {
                throw new \RuntimeException(\sprintf('%s changes data while it runs, so --dry-run and --write-sql would not rehearse it. Nothing was changed. Try it on a copy of the database, then run it without these options.', $plan->getVersion()));
            }
        }
    }
}
