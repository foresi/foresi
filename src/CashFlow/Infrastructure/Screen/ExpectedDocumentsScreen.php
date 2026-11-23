<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Screen;

use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\ForecastLine;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\Instance\Application\InstanceClock;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The documents a forecast counts, for the small dialog that sets or clears
 * the day a document is expected on, as Xero's expected payment date replaces
 * the due date. The dialog opens from an item in the sheet of a cell.
 */
final readonly class ExpectedDocumentsScreen
{
    public function __construct(
        private InstanceClock $clock,
        private LocaleSwitcher $locales,
    ) {
    }

    /**
     * The line of one document the forecast counts, so the dialog sets the
     * expected day only of a document the caller may read; null when none.
     */
    public function line(Forecast $forecast, string $type, string $id): ?ForecastLine
    {
        foreach ($forecast->periods as $period) {
            foreach ($period->lines as $line) {
                if (FlowCertainty::Document === $line->flow->certainty && $line->flow->subject->type === $type && $line->flow->subject->id === $id) {
                    return $line;
                }
            }
        }

        return null;
    }

    /**
     * The day a document is expected on, when someone set one.
     */
    public function expectedOn(ForecastLine $line): ?\DateTimeImmutable
    {
        return $line->flow->hasFlag(FlowFlag::ExpectedDate) ? $line->flow->expectedOn : null;
    }

    /**
     * A day with its year when it is not this year's, such as "3 Dec" or "28 Oct 2025".
     */
    public function day(\DateTimeImmutable $day): string
    {
        $skeleton = $day->format('Y') === $this->clock->today()->format('Y') ? 'dMMM' : 'dMMMy';
        $locale = $this->locales->getLocale();
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern($skeleton);
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone(), null, false === $pattern ? 'd MMM y' : $pattern);
        $text = $formatter->format($day);

        return false === $text ? $day->format('Y-m-d') : $text;
    }
}
