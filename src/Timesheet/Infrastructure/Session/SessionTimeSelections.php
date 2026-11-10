<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Infrastructure\Session;

use App\Timesheet\Application\TimeSelection;
use App\Timesheet\Application\TimeSelections;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Time chosen for an invoice, in the session of the person who chose it, under
 * a random reference. The latest few are kept, so an invoice form open in
 * another tab still issues while a new one is prepared, and the session never
 * grows with every dialog.
 */
final readonly class SessionTimeSelections implements TimeSelections
{
    private const string KEY = 'timesheet.selections';
    private const int KEPT = 5;

    public function __construct(private RequestStack $requests)
    {
    }

    public function save(TimeSelection $selection): string
    {
        $session = $this->requests->getSession();
        $reference = bin2hex(random_bytes(16));
        $kept = $this->kept();
        $kept[$reference] = $selection;
        $session->set(self::KEY, \array_slice($kept, -self::KEPT, preserve_keys: true));

        return $reference;
    }

    public function find(string $reference): ?TimeSelection
    {
        return $this->kept()[$reference] ?? null;
    }

    /**
     * @return array<string, TimeSelection>
     */
    private function kept(): array
    {
        $kept = $this->requests->getSession()->get(self::KEY, []);

        return \is_array($kept) ? array_filter($kept, static fn (mixed $selection, mixed $reference): bool => \is_string($reference) && $selection instanceof TimeSelection, \ARRAY_FILTER_USE_BOTH) : [];
    }
}
