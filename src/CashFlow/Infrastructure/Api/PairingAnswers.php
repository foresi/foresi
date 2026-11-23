<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\Pairing;
use Foresi\CashFlow\Domain\DocumentPairing;

/**
 * The pairings as the REST API and MCP answer them (CashFlowJson::pairing): the names of the planned items
 * from the store, the address of each document from the source that holds it.
 */
final readonly class PairingAnswers
{
    public function __construct(private CashFlowStore $store, private Pairing $pairing)
    {
    }

    /**
     * @param list<DocumentPairing> $pairings
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $pairings): array
    {
        $names = [];
        foreach ($this->store->plannedItems() as $item) {
            $names[$item->id()] = $item->name();
        }

        $documents = $this->pairing->documents($pairings);

        return array_map(static fn (DocumentPairing $pairing): array => CashFlowJson::pairing($pairing, $names, ($documents[$pairing->subjectType().':'.$pairing->subjectId()] ?? null)?->subject->url), $pairings);
    }

    /**
     * @return array<string, mixed>
     */
    public function row(DocumentPairing $pairing): array
    {
        return $this->rows([$pairing])[0];
    }
}
