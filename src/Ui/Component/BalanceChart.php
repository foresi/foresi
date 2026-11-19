<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Money in and out of each period as a pair of columns, with the closing
 * balance as a line over them and the minimum balance dashed across, as
 * QuickBooks' "money in and out" and Xero's cash in and out bars. The SVG is
 * drawn on the server, so it shows without JavaScript; the table under it is
 * its accessible equivalent, so the SVG is one image with a label. A small
 * Stimulus controller shows the figures of the period under the pointer.
 *
 * Amounts are whole units (crowns); the texts of the tooltip come formatted.
 */
#[AsTwigComponent]
final class BalanceChart
{
    public const int HEIGHT = 260;
    public const int TOP = 12;
    public const int BOTTOM = 28;
    public const int LEFT = 80;
    public const int RIGHT = 12;
    /** The width of one period: two columns and the gap around them. */
    public const int SLOT = 72;
    public const int BAR = 20;
    public const int GAP = 4;
    public const int RADIUS = 3;
    private const int MAX_PERIODS = 24;

    /**
     * @var list<array<string, mixed>> each with a string label, int in, out and closing, and the formatted texts inText, outText and closingText; optional bool below (the closing is under the minimum)
     */
    public array $periods = [];
    /** The minimum balance in whole units, or null when none is set. */
    public ?int $minimum = null;
    public ?string $minimumText = null;
    /** The accessible name of the chart. */
    public string $label = '';
    /** @var array{in?: string, out?: string, closing?: string, minimum?: string} the names of the series in the legend and the tooltip */
    public array $legend = [];

    /** @var list<array{label: string, in: int, out: int, closing: int, inText: string, outText: string, closingText: string, below: bool}> */
    private array $views = [];
    private float $low = 0.0;
    private float $high = 1.0;
    private float $step = 1.0;

    public function __construct(private readonly LocaleSwitcher $locales)
    {
    }

    #[PostMount]
    public function validate(): void
    {
        $count = \count($this->periods);
        if ($count < 1 || $count > self::MAX_PERIODS) {
            throw new \InvalidArgumentException(\sprintf('A balance chart shows 1 to %d periods.', self::MAX_PERIODS));
        }
        if ('' === $this->label) {
            throw new \InvalidArgumentException('A balance chart needs its accessible label.');
        }
        foreach (['in', 'out', 'closing'] as $series) {
            Style::requireString($this->legend, $series, 'The legend of a balance chart');
        }
        if (null !== $this->minimum) {
            Style::requireString($this->legend, 'minimum', 'The legend of a balance chart with a minimum');
        }
        $this->views = [];
        foreach ($this->periods as $period) {
            foreach (['in', 'out', 'closing'] as $amount) {
                if (!\is_int($period[$amount] ?? null)) {
                    throw new \InvalidArgumentException(\sprintf('A period of a balance chart needs the whole number "%s".', $amount));
                }
            }
            if ($period['in'] < 0 || $period['out'] < 0) {
                throw new \InvalidArgumentException('Money in and out of a balance chart are not negative.');
            }
            $this->views[] = [
                'label' => Style::requireString($period, 'label', 'A period of a balance chart'),
                'in' => $period['in'],
                'out' => $period['out'],
                'closing' => $period['closing'],
                'inText' => Style::requireString($period, 'inText', 'A period of a balance chart'),
                'outText' => Style::requireString($period, 'outText', 'A period of a balance chart'),
                'closingText' => Style::requireString($period, 'closingText', 'A period of a balance chart'),
                'below' => true === ($period['below'] ?? false),
            ];
        }
        $this->scale();
    }

    public function width(): int
    {
        return self::LEFT + \count($this->views) * self::SLOT + self::RIGHT;
    }

    public function height(): int
    {
        return self::HEIGHT;
    }

    /**
     * The value of a point of the plot as its y coordinate.
     */
    public function y(float $value): float
    {
        $plot = self::HEIGHT - self::TOP - self::BOTTOM;

        return round(self::TOP + ($this->high - $value) / ($this->high - $this->low) * $plot, 1);
    }

    /**
     * The gridlines: round values from the lowest to the highest, four or so steps apart.
     *
     * @return list<array{y: float, text: string, zero: bool}>
     */
    public function ticks(): array
    {
        // The steps are whole numbers, so the gridlines fall on whole units exactly.
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $ticks = [];
        // range(), not a loop of our own: a mutant of a loop's step never ends and takes the CI runner's memory with it.
        foreach (range($this->low, $this->high, $this->step) as $value) {
            $text = $formatter->format($value);
            $ticks[] = ['y' => $this->y($value), 'text' => false === $text ? '' : $text, 'zero' => 0.0 === $value];
        }

        return $ticks;
    }

    /**
     * Each period with its slot, its two columns as paths and the point of its closing.
     *
     * @return list<array{label: string, inText: string, outText: string, closingText: string, below: bool, x: int, centre: float, inPath: ?string, outPath: ?string, closingY: float}>
     */
    public function columns(): array
    {
        $base = $this->y(0.0);
        $columns = [];
        foreach ($this->views as $index => $period) {
            $x = self::LEFT + $index * self::SLOT;
            $centre = (float) ($x + self::SLOT / 2);
            $columns[] = [
                'label' => $period['label'],
                'inText' => $period['inText'],
                'outText' => $period['outText'],
                'closingText' => $period['closingText'],
                'below' => $period['below'],
                'x' => $x,
                'centre' => $centre,
                'inPath' => $this->bar($centre - self::GAP / 2 - self::BAR, $this->y($period['in']), $base),
                'outPath' => $this->bar($centre + self::GAP / 2, $this->y($period['out']), $base),
                'closingY' => $this->y($period['closing']),
            ];
        }

        return $columns;
    }

    /**
     * The closing balances joined from period to period.
     */
    public function line(): string
    {
        $points = array_map(static fn (array $column): string => self::number($column['centre']).','.self::number($column['closingY']), $this->columns());

        return 'M'.implode(' L', $points);
    }

    public function minimumY(): ?float
    {
        return null === $this->minimum ? null : $this->y($this->minimum);
    }

    /**
     * A column from the baseline up to its top, rounded at the top only; null for nothing.
     */
    private function bar(float $x, float $top, float $base): ?string
    {
        $height = $base - $top;
        if ($height <= 0.0) {
            return null;
        }
        $r = min(self::RADIUS, $height);
        $right = $x + self::BAR;

        return \sprintf(
            'M%s,%s V%s Q%s,%s %s,%s H%s Q%s,%s %s,%s V%s Z',
            self::number($x), self::number($base),
            self::number($top + $r),
            self::number($x), self::number($top), self::number($x + $r), self::number($top),
            self::number($right - $r),
            self::number($right), self::number($top), self::number($right), self::number($top + $r),
            self::number($base),
        );
    }

    /**
     * The range of the axis: zero, every amount and the minimum fit on it, at round steps.
     */
    private function scale(): void
    {
        $values = [0];
        foreach ($this->views as $period) {
            $values[] = $period['in'];
            $values[] = $period['out'];
            $values[] = $period['closing'];
        }
        if (null !== $this->minimum) {
            $values[] = $this->minimum;
        }
        $low = min($values);
        $high = max($values);
        if ($high === $low) {
            $high = $low + 1;
        }
        // Whole units: no gridline falls between two crowns.
        $this->step = max(1.0, $this->niceStep(($high - $low) / 4));
        $this->low = floor($low / $this->step) * $this->step;
        $this->high = ceil($high / $this->step) * $this->step;
    }

    /**
     * 1, 2 or 5 times a power of ten, the steps people read at a glance (Heckbert's nice numbers);
     * the step is at least 1, so every gridline is a whole number.
     */
    private function niceStep(float $raw): float
    {
        $magnitude = 10 ** floor(log10($raw));
        $normal = $raw / $magnitude;
        $nice = match (true) {
            $normal <= 1.0 => 1.0,
            $normal <= 2.0 => 2.0,
            $normal <= 5.0 => 5.0,
            default => 10.0,
        };

        return $nice * $magnitude;
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(\sprintf('%.1f', $value), '0'), '.');

        return '-0' === $text ? '0' : $text;
    }
}
