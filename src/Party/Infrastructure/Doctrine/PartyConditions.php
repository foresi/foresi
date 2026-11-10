<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportRow;
use App\Party\Application\ImportedBy;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyRole;

/**
 * The conditions that narrow the address book, as DQL over the alias "p": the
 * one search of the screen and of the API.
 */
final readonly class PartyConditions
{
    /**
     * @return array{string, array<string, int|string>} the condition and its parameters
     */
    public function build(string $text, ?PartyRole $role, bool $archived, ?ImportedBy $import = null): array
    {
        $where = $archived ? 'p.archivedAt IS NOT NULL' : 'p.archivedAt IS NULL';
        $parameters = [];
        if ($import instanceof ImportedBy) {
            // The import's rows name the records they wrote; the records a full replace archived are kept apart.
            $rows = 'p.id IN (SELECT r.recordId FROM '.ImportRow::class.' r WHERE IDENTITY(r.import) = :import AND r.outcome = :outcome)';
            $missing = 'p.id IN (SELECT m.recordId FROM '.ImportMissingRecord::class.' m WHERE IDENTITY(m.import) = :import AND m.outcome = :outcome)';
            $where = match ($import->outcome) {
                'archive' => $missing,
                'changed_after_import' => '('.$rows.' OR '.$missing.')',
                default => $rows,
            };
            $parameters = ['import' => $import->importId, 'outcome' => $import->outcome];
        }
        if ($role instanceof PartyRole) {
            $where .= ' AND '.$this->role($role->value);
        }
        $text = trim($text);
        if ('' !== $text) {
            $conditions = ['LOWER(p.displayName) LIKE :name ESCAPE \'!\'', 'LOWER(p.vatId) LIKE :vat ESCAPE \'!\''];
            $parameters['name'] = '%'.$this->like(mb_strtolower($text)).'%';
            $parameters['vat'] = '%'.$this->like(mb_strtolower((string) preg_replace('/\s+/', '', $text))).'%';
            $key = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text));
            if ('' !== $key) {
                $conditions[] = 'p.registrationKey LIKE :key ESCAPE \'!\'';
                $parameters['key'] = '%'.$this->like($key).'%';
            }
            $conditions[] = 'EXISTS (SELECT 1 FROM '.PartyEmail::class.' e WHERE e.party = p AND e.legalEntity IS NULL AND LOWER(e.address) LIKE :email ESCAPE \'!\')';
            $parameters['email'] = '%'.$this->like(mb_strtolower($text)).'%';
            $where .= ' AND ('.implode(' OR ', $conditions).')';
        }

        return [$where, $parameters];
    }

    /**
     * Roles live in a JSON column, which reads the same on every database as text.
     */
    public function role(string $role): string
    {
        return 'CAST_TEXT(p.roles) LIKE \'%"'.$role.'"%\'';
    }

    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
