<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Import;

use App\Import\Domain\RowOutcome;
use App\Import\Infrastructure\Controller\TargetPages;
use App\Party\Application\ImportedBy;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The address book, as the import screens link to it. */
final readonly class PartyImportPages implements TargetPages
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function targetKey(): string
    {
        return PartyImportTarget::KEY;
    }

    public function listLabelKey(): string
    {
        return 'party.title';
    }

    public function listUrl(?int $importId = null, ?RowOutcome $outcome = null): ?string
    {
        if (null === $importId || !$outcome instanceof RowOutcome) {
            return $this->urls->generate('app_contacts');
        }

        return \in_array($outcome->value, ImportedBy::OUTCOMES, true) ? $this->urls->generate('app_contacts', ['import' => $importId, 'outcome' => $outcome->value]) : null;
    }
}
