<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairableDocumentSource;

/**
 * A document source that holds the documents a test gives it.
 */
final class FakePairableDocuments implements PairableDocumentSource
{
    /** @var array<string, PairableDocument> */
    private array $documents = [];

    public function add(PairableDocument $document): PairableDocument
    {
        $this->documents[$document->subject->type.':'.$document->subject->id] = $document;

        return $document;
    }

    public function remove(string $subjectType, string $subjectId): void
    {
        unset($this->documents[$subjectType.':'.$subjectId]);
    }

    public function one(string $subjectType, string $subjectId): ?PairableDocument
    {
        return $this->documents[$subjectType.':'.$subjectId] ?? null;
    }

    public function of(string $counterpartyId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        foreach ($this->documents as $document) {
            $on = $document->on->format('Y-m-d');
            if ($document->counterpartyId === $counterpartyId && $on >= $from->format('Y-m-d') && $on <= $to->format('Y-m-d')) {
                yield $document;
            }
        }
    }

    public function since(\DateTimeImmutable $from): iterable
    {
        foreach ($this->documents as $document) {
            if ($document->on->format('Y-m-d') >= $from->format('Y-m-d')) {
                yield $document;
            }
        }
    }
}
