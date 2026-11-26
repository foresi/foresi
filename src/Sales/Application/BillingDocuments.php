<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Contract\BillingDocument;
use Foresi\Sales\Contract\BillingDocumentSource;
use Foresi\Sales\Contract\BillingDocumentType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The invoices and recurring invoices of the modules that issue them
 * (BillingDocumentSource), which fulfil the planned invoices of won deals.
 * Without such a module there are none, and nothing can be linked.
 */
final readonly class BillingDocuments
{
    public const int CHOICES = 50;

    /**
     * @param iterable<BillingDocumentSource> $sources
     */
    public function __construct(#[AutowireIterator(BillingDocumentSource::TAG)] private iterable $sources = [])
    {
    }

    public function installed(): bool
    {
        foreach ($this->sources as $source) {
            return true;
        }

        return false;
    }

    public function find(BillingDocumentType $type, string $id): ?BillingDocument
    {
        foreach ($this->sources as $source) {
            $document = $source->find($type, $id);
            if ($document instanceof BillingDocument) {
                return $document;
            }
        }

        return null;
    }

    /**
     * The active ones of the client, the newest first.
     *
     * @return list<BillingDocument>
     */
    public function ofClient(BillingDocumentType $type, string $clientPartyId): array
    {
        $documents = [];
        foreach ($this->sources as $source) {
            array_push($documents, ...$source->ofClient($type, $clientPartyId, self::CHOICES));
        }

        return \array_slice($documents, 0, self::CHOICES);
    }
}
