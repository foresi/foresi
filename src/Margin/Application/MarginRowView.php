<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * One row of the margin table: a client, the firm's own use or the costs
 * nobody has claimed. The key is what MarginCell::of() takes as its row:
 * "client:<contact id>", "own" or "unassigned". A client has its name (the id
 * when the contact no longer resolves); own and unassigned have none, the
 * screen names them. The cells are keyed by month (YYYY-MM), one for each
 * month of the range.
 */
final readonly class MarginRowView
{
    public const string OWN = 'own';
    public const string UNASSIGNED = 'unassigned';
    public const string CLIENT_PREFIX = 'client:';

    /**
     * @param 'client'|'own'|'unassigned'  $kind
     * @param array<string, MarginFigures> $cells
     */
    public function __construct(
        public string $key,
        public string $kind,
        public ?string $clientId,
        public ?string $name,
        public array $cells,
        public MarginFigures $total,
    ) {
    }

    /**
     * The label of a row for people who read English (REST, MCP): the name of a
     * client, which is its contact id when the contact no longer resolves, or
     * "Own use" or "Unassigned". A screen translates by the key instead.
     */
    public static function labelOf(string $key, ?string $name): string
    {
        return match (true) {
            self::OWN === $key => 'Own use',
            self::UNASSIGNED === $key => 'Unassigned',
            default => $name ?? $key,
        };
    }

    public function label(): string
    {
        return self::labelOf($this->key, $this->name);
    }
}
