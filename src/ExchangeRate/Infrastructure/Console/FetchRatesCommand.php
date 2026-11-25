<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Console;

use Foresi\ExchangeRate\Application\FetchRates;
use Foresi\ExchangeRate\Application\RateSources;
use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\RateSource;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches the exchange rates valid on a date, today by default, and stores them:
 * those of the source the firm converts at, or of any installed source with
 * --source. The schedule does this daily for the firm's source; the command is
 * for a date missed, to check the connection, or to fill another source before
 * the firm switches to it.
 */
#[AsCommand(name: 'app:exchange-rate:fetch', description: 'Fetch and store the exchange rates valid on a date (default today)')]
final readonly class FetchRatesCommand
{
    public function __construct(
        private FetchRates $fetching,
        private RateSource $source,
        private RateSources $sources,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The date as YYYY-MM-DD; today when omitted')]
        ?string $date = null,
        #[Option(description: 'The name of an installed source, such as cnb or ecb; the one the firm converts at when omitted')]
        ?string $source = null,
    ): int {
        $from = null === $source ? $this->source : $this->sources->named($source);
        if (!$from instanceof RateSource) {
            $installed = array_keys($this->sources->installed());
            $io->error(\sprintf('No source "%s" is installed. Installed: %s.', $source, [] === $installed ? 'none' : implode(', ', $installed)));

            return Command::INVALID;
        }

        $zone = $from->zone();
        $day = null === $date
            ? $this->clock->now()->setTimezone($zone)->setTime(0, 0)
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== ($date ?? $day->format('Y-m-d'))) {
            $io->error('The date must be a real day written as YYYY-MM-DD.');

            return Command::INVALID;
        }

        $published = $this->fetching->fetchFrom($from, $day);
        if (!$published instanceof PublishedRates) {
            $io->error('The source gave no usable answer; nothing was stored.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Stored %d rates of %s valid on %s, published on %s.', \count($published->quotes), $from->name(), $day->format('Y-m-d'), $published->publishedOn->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
