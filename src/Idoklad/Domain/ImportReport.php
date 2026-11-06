<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * What one import did, record by record and stage by stage. A refusal names its
 * reasons as translation keys; a mismatch and a refusal carry the values that
 * explain them.
 */
final class ImportReport
{
    /** @var list<array{stage: string, external_id: int, outcome: string, target_id: string|null, reasons: list<string>, values: array<string, int|string|bool|null>}> */
    private array $entries = [];

    /** @var array<string, array<string, int>> outcomes counted by stage */
    private array $counts = [];

    /**
     * @param list<string>                        $reasons translation keys
     * @param array<string, int|string|bool|null> $values
     */
    public function add(string $stage, int $externalId, ImportOutcome $outcome, ?string $targetId = null, array $reasons = [], array $values = []): void
    {
        $this->entries[] = ['stage' => $stage, 'external_id' => $externalId, 'outcome' => $outcome->value, 'target_id' => $targetId, 'reasons' => $reasons, 'values' => $values];
        $this->counts[$stage] ??= [];
        $this->counts[$stage][$outcome->value] = ($this->counts[$stage][$outcome->value] ?? 0) + 1;
    }

    /**
     * @return list<array{stage: string, external_id: int, outcome: string, target_id: string|null, reasons: list<string>, values: array<string, int|string|bool|null>}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return $this->counts;
    }
}
