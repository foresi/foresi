<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Prints the transports a worker consumes, in the order it takes them, on one
 * line for "messenger:consume $(bin/console app:worker:receivers)". A module
 * may bring a transport of its own, such as webhooks, so the list is that of
 * the installed modules: the schedule's ticks, the mail, the domain events and
 * the default queue first, then the modules' own transports, and the
 * low-priority register re-checks last. The failed transport is never
 * consumed; its messages are retried by hand (messenger:failed:retry).
 */
#[AsCommand(name: 'app:worker:receivers', description: 'Print the transports a worker consumes, in the order it takes them')]
final readonly class WorkerReceiversCommand
{
    /** Taken first, in this order. */
    public const array FIRST = ['scheduler_foresi', 'mail', 'events', 'async'];

    /** Taken only when every other transport is empty. */
    public const array LAST = ['registry'];

    private const string FAILED = 'failed';

    /**
     * @param ServiceProviderInterface<mixed> $receivers
     */
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ServiceProviderInterface $receivers,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $output->writeln(implode(' ', $this->receivers()));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    public function receivers(): array
    {
        // The locator knows each transport by its name and by its service id (messenger.transport.<name>).
        $names = array_values(array_filter(array_map(strval(...), array_keys($this->receivers->getProvidedServices())), static fn (string $name): bool => !str_contains($name, '.') && self::FAILED !== $name));
        $modules = array_diff($names, self::FIRST, self::LAST);
        sort($modules);

        return [...array_values(array_intersect(self::FIRST, $names)), ...$modules, ...array_values(array_intersect(self::LAST, $names))];
    }
}
