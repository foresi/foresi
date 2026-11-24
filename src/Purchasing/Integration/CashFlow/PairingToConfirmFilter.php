<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\CashFlow;

use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\PairingRefused;
use Foresi\Purchasing\Application\Query\ReceivedDocumentIdFilter;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListFieldCatalog;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The condition "To confirm pairing" of the list of received documents: the documents that Cash flow proposes
 * planned occurrences for and waits for a person to decide. It is offered with the other conditions of
 * the filter sheet and can be saved in a view.
 */
#[AutoconfigureTag(ReceivedDocumentIdFilter::ID_FILTER_TAG)]
final readonly class PairingToConfirmFilter implements ListFieldCatalog, ReceivedDocumentIdFilter
{
    public const string FIELD = 'pairing_to_confirm';

    public function __construct(private DocumentPairings $pairings)
    {
    }

    public function fields(string $listKey): array
    {
        return ReceivedDocumentLists::KEY === $listKey ? [new Field(self::FIELD, FieldType::Boolean, 'purchasing.pairing.filter')] : [];
    }

    public function field(): string
    {
        return self::FIELD;
    }

    public function documentIds(): array
    {
        try {
            return $this->pairings->toConfirm(ReceivedDocumentPairables::TYPE);
        } catch (PairingRefused) {
            return [];
        }
    }
}
