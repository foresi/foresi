<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Infrastructure\Console;

use App\ExchangeRate\Application\FetchRates;
use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\RateSource;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches the exchange rates valid on a date, today by default, and stores them.
 * The schedule does this daily; the command is for a date missed or to check.
 */
#[AsCommand(name: 'app:exchange-rate:fetch', description: 'Fetch and store the exchange rates valid on a date (default today)')]
final readonly class FetchRatesCommand
{
    public function __construct(
        private FetchRates $fetching,
        private RateSource $source,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The date as YYYY-MM-DD; today when omitted')]
        ?string $date = null,
    ): int {
        $zone = $this->source->zone();
        $day = null === $date
            ? $this->clock->now()->setTimezone($zone)->setTime(0, 0)
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== ($date ?? $day->format('Y-m-d'))) {
            $io->error('The date must be a real day written as YYYY-MM-DD.');

            return Command::INVALID;
        }

        $published = $this->fetching->fetch($day);
        if (!$published instanceof PublishedRates) {
            $io->error('The source gave no usable answer; nothing was stored.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Stored %d rates valid on %s, published on %s.', \count($published->quotes), $day->format('Y-m-d'), $published->publishedOn->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
