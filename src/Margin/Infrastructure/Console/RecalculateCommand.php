<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Console;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Contract\CostSharesRecalculation;
use Foresi\Margin\Domain\CostShare;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Counts the shares of every document the cost sources know about once, so the documents recorded before Margin was
 * installed get theirs. Idempotent: the shares of a document are replaced by what its mapping says now, so a second
 * run changes nothing.
 */
#[AsCommand(
    name: 'margin:recalculate',
    description: 'Count the cost shares of every recorded document again',
    help: <<<'HELP'
    Counts the cost shares of every document the cost sources know about, so the documents recorded
    before Margin was installed get theirs. Run it once after installing Margin next to existing
    documents, or to rebuild the shares after a mapping changed. It replaces the shares of each document
    with what its mapping says now and prints the number of documents and shares. It is idempotent: a
    second run changes nothing.
    HELP,
)]
final readonly class RecalculateCommand
{
    private const int BATCH = 100;

    public function __construct(
        private CostSharesRecalculation $recalculation,
        private CostSources $sources,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $considered = 0;
        foreach ($this->sources->documentIds() as $id) {
            $this->recalculation->recalculate($id);
            if (0 === ++$considered % self::BATCH) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $shares = $this->entityManager->getRepository(CostShare::class)->count([]);
        $io->success(\sprintf('Considered %d documents.', $considered));
        $io->table(['Documents', 'Shares'], [[$considered, $shares]]);

        return Command::SUCCESS;
    }
}
