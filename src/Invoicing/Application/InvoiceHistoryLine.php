<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What one step in the history of an invoice did to one of its lines.
 */
final readonly class InvoiceHistoryLine
{
    public const string ADDED = 'added';
    public const string CHANGED = 'changed';
    public const string REMOVED = 'removed';

    /**
     * @param int          $number       counted from 1, as the document shows it
     * @param string       $text         what the position holds after the step; a removed line, what it held
     * @param string       $change       one of the constants
     * @param list<string> $fields       names of the other fields that changed
     * @param string|null  $previousText what the position held before, when the text changed
     */
    public function __construct(public int $number, public string $text, public string $change, public array $fields, public ?string $previousText = null)
    {
    }
}
