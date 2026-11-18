<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View;

use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Shared\Application\View\SavedViewStore;
use Foresi\Shared\Domain\View\SavedView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stores the views the installed modules ship (spec D12). It adds the ones missing
 * and brings the ones nobody edited or hid to what the module now declares; a view
 * someone changed or hid is theirs and stays as it is. Running it again changes
 * nothing, so the deploy and the install both run it.
 */
#[AsCommand(name: 'app:views:sync', description: 'Store the default views of the installed modules, leaving edited and hidden ones alone')]
final readonly class SyncViewsCommand
{
    public function __construct(
        private ListDefinitions $lists,
        private SavedViewStore $store,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        [$added, $updated, $kept] = [0, 0, 0];
        foreach ($this->lists->all() as $list) {
            foreach ($list->defaults as $default) {
                $systemKey = $list->systemKey($default);
                $filters = $default->filter->toArray();
                $saved = $this->store->bySystemKey($systemKey);
                if (!$saved instanceof SavedView) {
                    $this->store->add(SavedView::shipped($list->key, $systemKey, $default->name, $filters, [], $default->showCount, $default->placement, $default->position, $now));
                    ++$added;
                } elseif ($saved->reship($default->name, $filters, [], $default->showCount, $default->placement, $default->position, $now)) {
                    ++$updated;
                } elseif (!$saved->isPristine()) {
                    ++$kept;
                }
            }
        }
        $this->store->flush();
        $io->success(\sprintf('Default views: %d added, %d updated, %d edited or hidden left alone.', $added, $updated, $kept));

        return Command::SUCCESS;
    }
}
