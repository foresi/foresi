<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\RecordMatch;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\Import;
use App\Import\Domain\ImportRow;
use App\Import\Domain\Mapping;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\RowError;
use App\Import\Domain\RowOutcome;
use App\Import\Domain\ValueNormaliser;

/**
 * Reads staged rows of one import through its mapping, the same way for the check
 * and the run: normalise the mapped cells, let the target validate them, match the
 * key and decide the outcome. Writes nothing.
 */
final readonly class RowReader
{
    /** @var array<int, FieldDefinition> mapped fields by column */
    private array $columns;
    private ?string $matchKey;
    private ?int $keyColumn;
    private NormalisationOptions $normalisation;
    private Mapping $mapping;

    public function __construct(
        private ImportTarget $target,
        private Import $import,
        private ValueNormaliser $normaliser,
    ) {
        $fields = [];
        foreach ($target->fields() as $field) {
            $fields[$field->key] = $field;
        }
        $this->mapping = $import->mapping();
        $columns = [];
        foreach ($this->mapping->columns as $column => $key) {
            if (null !== $key && isset($fields[$key])) {
                $columns[$column] = $fields[$key];
            }
        }
        $this->columns = $columns;
        $this->matchKey = self::matchKeyOf($target, $this->mapping, $import->options()->matchKey);
        $keyColumn = array_search($this->matchKey, $this->mapping->columns, true);
        $this->keyColumn = null === $this->matchKey || false === $keyColumn ? null : $keyColumn;
        $options = $import->options();
        $this->normalisation = new NormalisationOptions($options->dateOrder, $options->decimalSeparator);
    }

    /**
     * The match key the user chose, or else the first key of the target that a column is mapped to.
     */
    public static function matchKeyOf(ImportTarget $target, Mapping $mapping, ?string $chosen): ?string
    {
        $mapped = array_values(array_filter($mapping->columns, static fn (?string $key): bool => null !== $key));
        if (null !== $chosen) {
            return \in_array($chosen, $target->matchKeys(), true) && \in_array($chosen, $mapped, true) ? $chosen : null;
        }
        foreach ($target->matchKeys() as $key) {
            if (\in_array($key, $mapped, true)) {
                return $key;
            }
        }

        return null;
    }

    public function matchKey(): ?string
    {
        return $this->matchKey;
    }

    public function read(ImportRow $row): RowEvaluation
    {
        $raw = $row->raw();
        $values = [];
        $errors = [];
        foreach ($this->columns as $column => $field) {
            $value = $this->normaliser->normalise($field, $raw[$column] ?? '', $this->normalisation, $this->mapping->valueMap($field->key));
            if ($value instanceof RowError) {
                $errors[] = $value;
                continue;
            }
            $values[$field->key] = $value;
        }
        $failed = array_map(static fn (RowError $error): string => $error->field, $errors);
        foreach ($this->target->validate($values) as $error) {
            // A field that could not be read is reported once, not again by the target.
            if (!\in_array($error->field, $failed, true)) {
                $errors[] = $error;
            }
        }
        $matchValue = null === $this->matchKey ? null : ImportRow::fingerprint($values[$this->matchKey] ?? null);

        return new RowEvaluation($values, $errors, $matchValue, null === $this->keyColumn ? '' : ($raw[$this->keyColumn] ?? ''));
    }

    /**
     * @return list<RecordMatch>
     */
    public function matches(RowEvaluation $row): array
    {
        return null === $row->matchValue || null === $this->matchKey ? [] : $this->target->match($this->matchKey, $row->values);
    }

    /**
     * The outcome of a row and the errors that explain it.
     *
     * @param list<RecordMatch> $matches
     *
     * @return array{RowOutcome, list<RowError>}
     */
    public function decide(RowEvaluation $row, array $matches): array
    {
        if ([] !== $row->errors) {
            return [RowOutcome::Error, $row->errors];
        }
        if (null === $row->matchValue) {
            return [RowOutcome::Create, []];
        }
        $outcome = RowOutcome::forMatches($this->import->options()->mode, \count($matches), [] !== $matches && $matches[0]->archived);
        if (RowOutcome::Error === $outcome) {
            return [$outcome, [new RowError((string) $this->matchKey, 'import.error.ambiguous_match', ['value' => $row->keyText, 'count' => \count($matches)])]];
        }

        return [$outcome, []];
    }

    /** The error of a row whose key an earlier row of the file already used. */
    public function duplicateError(ImportRow $row): RowError
    {
        return new RowError((string) $this->matchKey, 'import.error.duplicate_in_file', ['value' => null === $this->keyColumn ? '' : ($row->raw()[$this->keyColumn] ?? '')]);
    }
}
