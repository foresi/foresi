<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use Doctrine\ORM\Mapping as ORM;

/** What the user decided about how an import treats records and values. */
#[ORM\Embeddable]
final readonly class ImportOptions
{
    /**
     * @param ReplaceScope|null $scope               set for a full replace, null otherwise
     * @param string|null       $matchKey            the field that finds the existing record
     * @param int|null          $archiveConfirmation the number of records to archive the user confirmed
     */
    public function __construct(
        #[ORM\Column(name: 'mode', type: 'string', length: 32, enumType: ImportMode::class)]
        public ImportMode $mode,
        #[ORM\Column(name: 'scope', type: 'string', length: 32, nullable: true, enumType: ReplaceScope::class)]
        public ?ReplaceScope $scope,
        #[ORM\Column(name: 'match_key', type: 'string', length: 64, nullable: true)]
        public ?string $matchKey,
        #[ORM\Column(name: 'blank_cells', type: 'string', length: 16, enumType: BlankCells::class)]
        public BlankCells $blankCells,
        #[ORM\Column(name: 'date_order', type: 'string', length: 16, enumType: DateOrder::class)]
        public DateOrder $dateOrder,
        #[ORM\Column(name: 'decimal_separator', type: 'string', length: 16, enumType: DecimalSeparator::class)]
        public DecimalSeparator $decimalSeparator,
        #[ORM\Column(name: 'archive_confirmation', type: 'integer', nullable: true)]
        public ?int $archiveConfirmation,
    ) {
        if (ImportMode::FullReplace === $mode && !$scope instanceof ReplaceScope) {
            throw new \DomainException('A full replace needs a scope.');
        }
        if (ImportMode::FullReplace !== $mode && $scope instanceof ReplaceScope) {
            throw new \DomainException('Only a full replace has a scope.');
        }
        if (null !== $archiveConfirmation && $archiveConfirmation < 1) {
            throw new \DomainException('The confirmed number of archived records must be positive.');
        }
    }

    /** What a new import starts with. */
    public static function standard(): self
    {
        return new self(ImportMode::AddMissing, null, null, BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null);
    }
}
