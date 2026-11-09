<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Twig;

use App\Tenancy\Application\TenantContext;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeQueries;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Time on the screens: a length of work in hours and minutes, as "3 h 20 min",
 * which the duration field reads back; and the timer the signed-in person
 * runs, for the bar at the top of every page.
 */
final readonly class TimeFunctions
{
    public function __construct(
        private TimeQueries $time,
        private TenantContext $context,
        private TranslatorInterface $translator,
    ) {
    }

    #[AsTwigFilter('duration')]
    public function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $this->translator->trans('work.time.hours', ['hours' => (string) $hours]);
        }
        if ($rest > 0 || 0 === $hours) {
            $parts[] = $this->translator->trans('work.time.minutes', ['minutes' => (string) $rest]);
        }

        return implode(' ', $parts);
    }

    /**
     * Null without an organisation chosen, whose people alone run timers.
     */
    #[AsTwigFunction('running_timer')]
    public function runningTimer(): ?TimeEntryRow
    {
        return $this->context->has() ? $this->time->running() : null;
    }
}
