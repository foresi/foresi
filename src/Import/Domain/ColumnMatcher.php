<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

use App\Import\Domain\Preset\SourcePreset;

/** Proposes the target field of every source column; the user confirms or changes it. */
final readonly class ColumnMatcher
{
    private const float SIMILARITY = 0.8;
    private const float SAMPLE_SHARE = 0.8;

    public function __construct(private ValueNormaliser $normaliser)
    {
    }

    /**
     * Layers run in order and a field goes to one column only: the first column
     * that reaches it in the strongest layer.
     *
     * @param list<string>                   $headers
     * @param array<int, list<string>>       $samples cell texts by column
     * @param list<FieldDefinition>          $fields
     * @param callable(string): list<string> $labels  the translated names of a label key
     *
     * @return list<ColumnProposal> one per header, in column order
     */
    public function propose(array $headers, array $samples, array $fields, ?Mapping $remembered, ?SourcePreset $preset, callable $labels): array
    {
        $known = [];
        foreach ($fields as $field) {
            $known[$field->key] = $field;
        }
        $names = array_map(HeaderName::normalise(...), $headers);
        $compact = array_map(HeaderName::compact(...), $headers);
        /** @var array<int, ColumnProposal> $proposals */
        $proposals = [];
        $taken = [];
        $claim = static function (int $column, ?string $fieldKey, MatchReason $reason) use (&$proposals, &$taken): void {
            if (null !== $fieldKey && isset($taken[$fieldKey])) {
                $proposals[$column] = new ColumnProposal($column, null, MatchReason::None);

                return;
            }
            if (null !== $fieldKey) {
                $taken[$fieldKey] = true;
            }
            $proposals[$column] = new ColumnProposal($column, $fieldKey, $reason);
        };

        foreach (array_keys($headers) as $column) {
            if ($remembered instanceof Mapping && \array_key_exists($column, $remembered->columns)) {
                $key = $remembered->columns[$column];
                if (null === $key || isset($known[$key])) {
                    $claim($column, $key, MatchReason::Remembered);
                }
            }
        }
        if ($preset instanceof SourcePreset) {
            $presetColumns = [];
            foreach ($preset->columns() as $header => $fieldKey) {
                $presetColumns[HeaderName::normalise((string) $header)] = $fieldKey;
            }
            foreach ($names as $column => $name) {
                $key = $presetColumns[$name] ?? null;
                if (!isset($proposals[$column]) && null !== $key && isset($known[$key])) {
                    $claim($column, $key, MatchReason::Preset);
                }
            }
        }

        $candidates = [];
        foreach ($fields as $field) {
            $candidates[$field->key] = $this->candidates($field, $labels);
        }
        foreach (array_keys($names) as $column) {
            if (isset($proposals[$column])) {
                continue;
            }
            foreach ($candidates as $fieldKey => $spellings) {
                if (\in_array($compact[$column], $spellings, true)) {
                    $claim($column, $fieldKey, MatchReason::SameName);
                    break;
                }
            }
        }
        foreach ($compact as $column => $name) {
            if (isset($proposals[$column])) {
                continue;
            }
            $best = $this->mostSimilar($name, $samples[$column] ?? [], $fields, $candidates, $taken);
            if (null !== $best) {
                $claim($column, $best, MatchReason::SimilarName);
            }
        }

        $result = [];
        foreach (array_keys($headers) as $column) {
            $result[] = $proposals[$column] ?? new ColumnProposal($column, null, MatchReason::None);
        }

        return $result;
    }

    /**
     * @param callable(string): list<string> $labels
     *
     * @return list<string> the compact spellings that name the field
     */
    private function candidates(FieldDefinition $field, callable $labels): array
    {
        $spellings = [$field->key, ...$field->aliases, ...$labels($field->labelKey)];

        return array_values(array_unique(array_filter(array_map(HeaderName::compact(...), $spellings), static fn (string $name): bool => '' !== $name)));
    }

    /**
     * @param list<string>                $samples
     * @param list<FieldDefinition>       $fields
     * @param array<string, list<string>> $candidates
     * @param array<string, true>         $taken
     */
    private function mostSimilar(string $name, array $samples, array $fields, array $candidates, array $taken): ?string
    {
        if ('' === $name) {
            return null;
        }
        $samples = array_values(array_filter(array_map(Text::clean(...), $samples), static fn (string $sample): bool => '' !== $sample));
        $best = null;
        $bestRatio = self::SIMILARITY;
        foreach ($fields as $field) {
            if (isset($taken[$field->key])) {
                continue;
            }
            foreach ($candidates[$field->key] as $spelling) {
                $ratio = 1 - $this->distance($name, $spelling) / max(\strlen($name), \strlen($spelling));
                if ($ratio >= $bestRatio && (null === $best || $ratio > $bestRatio) && $this->accepts($field, $samples)) {
                    $best = $field->key;
                    $bestRatio = $ratio;
                }
            }
        }

        return $best;
    }

    /**
     * @param list<string> $samples non-empty cell texts
     */
    private function accepts(FieldDefinition $field, array $samples): bool
    {
        if ([] === $samples) {
            return false;
        }
        $options = NormalisationOptions::detect($samples);
        $read = \count(array_filter($samples, fn (string $sample): bool => !$this->normaliser->normalise($field, $sample, $options) instanceof RowError));

        return $read / \count($samples) >= self::SAMPLE_SHARE;
    }

    /**
     * Edit distance in which swapping two neighbouring letters ("emial") costs one edit, as a typo does.
     * Names are ASCII after normalisation, so bytes are letters.
     */
    private function distance(string $a, string $b): int
    {
        $rows = [];
        for ($i = 0; $i <= \strlen($a); ++$i) {
            $rows[$i][0] = $i;
        }
        for ($j = 0; $j <= \strlen($b); ++$j) {
            $rows[0][$j] = $j;
        }
        for ($i = 1; $i <= \strlen($a); ++$i) {
            for ($j = 1; $j <= \strlen($b); ++$j) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $rows[$i][$j] = min($rows[$i - 1][$j] + 1, $rows[$i][$j - 1] + 1, $rows[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $rows[$i][$j] = min($rows[$i][$j], $rows[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $rows[\strlen($a)][\strlen($b)];
    }
}
