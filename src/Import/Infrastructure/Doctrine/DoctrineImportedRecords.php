<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Infrastructure\Doctrine;

use Foresi\Import\Application\Imports;
use Foresi\Import\Contract\ImportedRecords;
use Foresi\Import\Contract\RowOutcome;
use Foresi\Import\Domain\ImportMissingRecord;
use Foresi\Import\Domain\ImportRow;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The records an import wrote are named by its rows; the records a full
 * replace archived are kept apart, and the undo can leave either alone.
 */
#[AsAlias(ImportedRecords::class)]
final readonly class DoctrineImportedRecords implements ImportedRecords
{
    public function __construct(private Imports $imports, private UrlGeneratorInterface $urls)
    {
    }

    public function fileName(string $importId): ?string
    {
        return $this->imports->find($importId)?->originalName();
    }

    public function summaryUrl(string $importId): string
    {
        return $this->urls->generate('app_import_show', ['id' => $importId]);
    }

    public function condition(string $importId, RowOutcome $outcome, string $recordId, string $prefix = 'import'): array
    {
        $import = ':'.$prefix.'_id';
        $outcomeParameter = ':'.$prefix.'_outcome';
        $rows = $recordId.' IN (SELECT '.$prefix.'_r.recordId FROM '.ImportRow::class.' '.$prefix.'_r WHERE IDENTITY('.$prefix.'_r.import) = '.$import.' AND '.$prefix.'_r.outcome = '.$outcomeParameter.')';
        $missing = $recordId.' IN (SELECT '.$prefix.'_m.recordId FROM '.ImportMissingRecord::class.' '.$prefix.'_m WHERE IDENTITY('.$prefix.'_m.import) = '.$import.' AND '.$prefix.'_m.outcome = '.$outcomeParameter.')';
        $condition = match ($outcome) {
            RowOutcome::Archive => $missing,
            RowOutcome::ChangedAfterImport => '('.$rows.' OR '.$missing.')',
            default => $rows,
        };

        return [$condition, [$prefix.'_id' => $importId, $prefix.'_outcome' => $outcome->value]];
    }
}
