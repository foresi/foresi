<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Screen;

use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\ForecastLine;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The documents a forecast counts, as Float lists the invoices and bills it
 * expects and Xero's expected payment date replaces the due date: each open
 * invoice, received document and the next invoice of each recurring invoice,
 * with its due day, the day it is expected on and its flags. A row opens the
 * document in its own sheet; a document's expected day is set or cleared in a
 * small dialog. Planned items have their own pages, so they are left out.
 */
final readonly class ExpectedDocumentsScreen
{
    /** Where each kind of document opens, by the subject type its source gives. */
    private const array SHEETS = [
        'invoice' => 'app_invoice_show',
        'recurring_invoice' => 'app_recurring_show',
        'received_document' => 'app_purchasing_document',
    ];

    public function __construct(
        private InstanceClock $clock,
        private MoneyText $money,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the columns of Ui:DataTable
     */
    public function columns(): array
    {
        return [
            ['key' => 'document', 'label' => $this->trans('cash_flow.expected.column.document'), 'truncate' => true],
            ['key' => 'source', 'label' => $this->trans('cash_flow.expected.column.source'), 'phone' => false],
            ['key' => 'due', 'label' => $this->trans('cash_flow.expected.column.due'), 'phone' => false],
            ['key' => 'expected', 'label' => $this->trans('cash_flow.expected.column.expected')],
            ['key' => 'flags', 'label' => $this->trans('cash_flow.expected.column.flags'), 'phone' => false],
            ['key' => 'amount', 'label' => $this->trans('cash_flow.expected.column.amount'), 'align' => 'right'],
        ];
    }

    /**
     * The rows of Ui:DataTable, in the order the forecast counts them.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Forecast $forecast, ?string $view = null): array
    {
        $rows = [];
        foreach ($this->lines($forecast) as $line) {
            $rows[] = $this->row($line, $view);
        }

        return $rows;
    }

    /**
     * The line of one document the forecast counts, so the dialog sets the
     * expected day only of a document the caller may read; null when none.
     */
    public function line(Forecast $forecast, string $type, string $id): ?ForecastLine
    {
        return array_find($this->lines($forecast), static fn (ForecastLine $line): bool => FlowCertainty::Document === $line->flow->certainty && $line->flow->subject->type === $type && $line->flow->subject->id === $id);
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

    /**
     * The document lines of every period: a recurring invoice only by its next
     * invoice, so a monthly one is one row and not twelve.
     *
     * @return list<ForecastLine>
     */
    private function lines(Forecast $forecast): array
    {
        $lines = [];
        $seen = [];
        foreach ($forecast->periods as $period) {
            foreach ($period->lines as $line) {
                $subject = $line->flow->subject;
                if (!\in_array($line->flow->certainty, [FlowCertainty::Document, FlowCertainty::Schedule], true) || 'planned_item' === $subject->type) {
                    continue;
                }
                $key = $subject->type.':'.$subject->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ForecastLine $line, ?string $view): array
    {
        $flow = $line->flow;
        $subject = $flow->subject;
        $route = self::SHEETS[$subject->type] ?? null;
        $expected = $this->expectedOn($line);
        // Money out with a minus, as the period table subtracts it.
        $amount = $this->money->shown(FlowDirection::In === $flow->direction ? $flow->amount : Money::ofMinor(-$flow->amount->minor(), $flow->amount->currency()));
        $badges = [];
        // Awaited is past but not late: neutral, never red.
        foreach ([[FlowFlag::Overdue, 'red'], [FlowFlag::Awaited, 'gray'], [FlowFlag::ExpectedDate, 'green'], [FlowFlag::NoDueDate, 'amber'], [FlowFlag::Estimated, 'amber']] as [$flag, $tone]) {
            if ($flow->hasFlag($flag)) {
                $badges[] = ['label' => $this->trans(ForecastScreen::flagKey($flag, $flow->direction)), 'tone' => $tone];
            }
        }
        $actions = [];
        if (FlowCertainty::Document === $flow->certainty) {
            $parameters = ['type' => $subject->type, 'id' => $subject->id] + (null === $view ? [] : ['view' => $view]);
            $actions[] = [
                'label' => $this->trans($expected instanceof \DateTimeImmutable ? 'cash_flow.expected.action.change' : 'cash_flow.expected.action.set'),
                'href' => $this->urls->generate('app_cash_flow_expected', $parameters),
                'method' => 'GET',
                'icon' => 'calendar',
                'dialog' => true,
            ];
        }

        return [
            'id' => $subject->type.'-'.$subject->id,
            // A document opens in its own sheet over the page (docs/design/overlays.md).
            'href' => null === $route ? null : $this->urls->generate($route, ['id' => $subject->id]),
            'label' => $flow->label,
            'secondary' => $amount.' · '.$this->day($expected ?? $line->dueOn()),
            'cells' => [
                'document' => $flow->label,
                'source' => ['badges' => [['label' => $this->subjectName($subject->type), 'tone' => 'gray']]],
                'due' => $flow->hasFlag(FlowFlag::NoDueDate) ? ['empty' => $this->trans('cash_flow.expected.none.due')] : $this->day($line->dueOn()),
                'expected' => $expected instanceof \DateTimeImmutable ? $this->day($expected) : ['empty' => $this->trans('cash_flow.expected.none.expected')],
                'flags' => [] === $badges ? ['empty' => $this->trans('cash_flow.expected.none.flags')] : ['badges' => $badges],
                'amount' => $amount,
            ],
            'actions' => $actions,
        ];
    }

    private function subjectName(string $type): string
    {
        $key = 'cash_flow.subject.'.$type;
        $name = $this->translator->trans($key);

        return $name !== $key ? $name : $type;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
