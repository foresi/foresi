<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Console;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Contract\DocumentPairer;
use Foresi\CashFlow\Contract\PairableDocumentSource;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\Instance\Application\InstanceClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks at the documents of the last 13 months once, so the documents recorded before pairing existed get their
 * pairings. Idempotent: a document that was decided before is left as it is, and a second run changes nothing.
 */
#[AsCommand(name: 'cashflow:pairing:backfill', description: 'Pair the documents of the last 13 months with planned occurrences')]
final readonly class BackfillPairingsCommand
{
    /**
     * @param iterable<PairableDocumentSource> $sources
     */
    public function __construct(
        private DocumentPairer $pairer,
        private CashFlowStore $store,
        private InstanceClock $clock,
        #[AutowireIterator(PairableDocumentSource::TAG)]
        private iterable $sources,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $from = $this->clock->today()->modify('-13 months');
        $considered = 0;
        foreach ($this->sources as $source) {
            foreach ($source->since($from) as $document) {
                $this->pairer->consider($document);
                ++$considered;
            }
        }
        $counts = array_fill_keys(array_map(static fn (PairingState $state): string => $state->value, PairingState::cases()), 0);
        foreach ($this->store->pairings() as $pairing) {
            ++$counts[$pairing->state()->value];
        }
        $io->success(\sprintf('Considered %d documents dated from %s.', $considered, $from->format('Y-m-d')));
        $io->table(['State', 'Pairings'], array_map(static fn (string $state, int $count): array => [$state, $count], array_keys($counts), $counts));

        return Command::SUCCESS;
    }
}
