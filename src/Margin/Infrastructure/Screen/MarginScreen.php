<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Screen;

use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\KnownGap;
use Foresi\Margin\Application\MarginCellItem;
use Foresi\Margin\Application\MarginCellView;
use Foresi\Margin\Application\MarginFigures;
use Foresi\Margin\Application\MarginRowView;
use Foresi\Margin\Application\MarginTableView;
use Foresi\Margin\Application\UnrebilledOccurrence;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Infrastructure\Controller\MarginPageController;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the Margin page shows of a MarginTable and of a MarginCell: the months
 * as columns, per client the revenue, the costs and the margin as rows, then
 * the firm's own use and what nobody has claimed, and the figures in CZK. The
 * page asks MarginTable and MarginCell directly, so the screen, the REST
 * endpoints and the MCP tools read one table. This class only names, formats
 * and links.
 */
final readonly class MarginScreen
{
    /** The assignment of one document, a dialog of the Purchasing integration (the route exists only when Purchasing is installed). */
    private const string ASSIGN_ROUTE = 'app_margin_assign';

    public function __construct(
        private MoneyText $money,
        private CostSources $sources,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{
     *     range: string,
     *     months: list<array{key: string, label: string}>,
     *     empty: bool,
     *     groups: list<array{key: string, kind: string, label: string, badge: ?string, lines: list<array{kind: string, label: string, cells: list<array{text: string, href: ?string, label: ?string, unrebilled: ?string}>, total: string}>}>,
     *     totals: list<array{kind: string, label: string, cells: list<array{text: string, href: ?string, label: ?string, unrebilled: ?string}>, total: string}>,
     *     gaps: ?array{text: string, documents: list<array{label: string, href: ?string}>}
     * }
     */
    public function table(MarginTableView $table): array
    {
        $groups = [];
        foreach ($table->rows as $row) {
            $client = $this->isClient($row->key);
            $groups[] = [
                'key' => $row->key,
                'kind' => $row->kind,
                'label' => $this->rowLabel($row->key, $row->name),
                'badge' => MarginRowView::UNASSIGNED === $row->key && $table->unassignedDocuments > 0 ? $this->trans('margin.table.unassigned_documents', ['count' => $table->unassignedDocuments]) : null,
                // Costs are all a row of the firm's own use or of unassigned has, so it has no revenue and no margin line.
                'lines' => $client ? $this->lines($row, $table->months) : [$this->line('costs', $row, $table->months)],
            ];
        }
        $everything = array_map(static fn (string $month): array => [$month, $table->monthTotals[$month] ?? MarginFigures::none()], $table->months);

        return [
            'range' => $this->monthLabel($table->from).' – '.$this->monthLabel($table->to),
            'months' => array_map(fn (string $month): array => ['key' => $month, 'label' => $this->monthLabel($month)], $table->months),
            // Only when no row has a figure, Unassigned included: a cost nobody claimed always shows the table.
            'empty' => array_all($table->rows, fn (MarginRowView $row): bool => !$this->isClient($row->key) && $row->total->isEmpty()),
            'groups' => $groups,
            'totals' => array_map(
                fn (string $kind): array => [
                    'kind' => $kind,
                    'label' => $this->trans('margin.table.line.'.$kind),
                    'cells' => array_map(fn (array $month): array => ['text' => $this->amount($this->figure($month[1], $kind)), 'href' => null, 'label' => null, 'unrebilled' => null], $everything),
                    'total' => $this->amount($this->figure($table->total, $kind)),
                ],
                ['revenue', 'costs', 'margin'],
            ),
            'gaps' => $this->gaps($table->knownGaps),
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     month: string,
     *     unassigned: bool,
     *     revenue: array{items: list<array<string, mixed>>, total: string},
     *     costs: array{items: list<array<string, mixed>>, total: string},
     *     margin: string,
     *     unrebilled: list<array{item: string, itemHref: ?string, day: string, templateHref: string}>,
     *     showRevenue: bool
     * }
     */
    public function cell(MarginCellView $cell): array
    {
        $unassigned = MarginRowView::UNASSIGNED === $cell->row;

        return [
            'title' => $this->rowLabel($cell->row, $cell->name).' · '.$this->monthLabel($cell->month),
            'month' => $cell->month,
            'unassigned' => $unassigned,
            'revenue' => ['items' => array_map($this->item(...), $cell->revenue), 'total' => $this->amount($cell->figures->revenue)],
            'costs' => ['items' => array_map(fn (MarginCellItem $item): array => $this->item($item, $unassigned), $cell->costs), 'total' => $this->amount($cell->figures->costs)],
            'margin' => $this->amount($cell->figures->margin),
            'unrebilled' => array_map(fn (UnrebilledOccurrence $occurrence): array => [
                'item' => $occurrence->itemName,
                'itemHref' => $occurrence->itemUrl,
                'day' => $occurrence->occurrenceOn->format('Y-m-d'),
                'templateHref' => $occurrence->templateUrl,
            ], $cell->unrebilled),
            // Only a client earns revenue; the firm's own use and unassigned are costs alone.
            'showRevenue' => $this->isClient($cell->row),
        ];
    }

    /**
     * The first and the last month of a range as the page writes them: "Sep 2026".
     */
    public function monthLabel(string $month): string
    {
        $day = new \DateTimeImmutable($month.'-01', new \DateTimeZone('UTC'));
        $locale = $this->locales->getLocale();
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern('yMMM');
        $text = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, false === $pattern ? 'MMM y' : $pattern)->format($day);

        return false === $text ? $month : $text;
    }

    /**
     * The URL of the sheet over one cell.
     */
    public function cellUrl(string $row, string $month): string
    {
        return $this->urls->generate(MarginPageController::CELL_ROUTE, ['row' => $row, 'month' => $month]);
    }

    private function isClient(string $key): bool
    {
        return str_starts_with($key, MarginRowView::CLIENT_PREFIX);
    }

    private function rowLabel(string $key, ?string $name): string
    {
        return match ($key) {
            MarginRowView::OWN => $this->trans('margin.screen.own'),
            MarginRowView::UNASSIGNED => $this->trans('margin.table.unassigned'),
            default => MarginRowView::labelOf($key, $name),
        };
    }

    /**
     * @param list<string> $months
     *
     * @return list<array{kind: string, label: string, cells: list<array{text: string, href: ?string, label: ?string, unrebilled: ?string}>, total: string}>
     */
    private function lines(MarginRowView $row, array $months): array
    {
        return array_map(fn (string $kind): array => $this->line($kind, $row, $months), ['revenue', 'costs', 'margin']);
    }

    /**
     * @param list<string> $months
     *
     * @return array{kind: string, label: string, cells: list<array{text: string, href: ?string, label: ?string, unrebilled: ?string}>, total: string}
     */
    private function line(string $kind, MarginRowView $row, array $months): array
    {
        $cells = [];
        foreach ($months as $month) {
            $figures = $row->cells[$month] ?? MarginFigures::none();
            $money = $this->figure($figures, $kind);
            // The revenue and the costs open what stands behind them; the margin is their difference and says nothing more.
            // A cost that was paid and not rebilled is behind the costs of its client too, even when the client bears no other.
            $unrebilled = 'costs' === $kind ? $figures->unrebilled : 0;
            $opens = 'margin' !== $kind && (!$money->isZero() || $unrebilled > 0);
            $cells[] = [
                'text' => $this->amount($money),
                // A neutral note, not an alarm: the cost may be rebilled later.
                'unrebilled' => $unrebilled > 0 ? $this->trans('margin.table.unrebilled', ['count' => $unrebilled]) : null,
                'href' => $opens ? $this->cellUrl($row->key, $month) : null,
                // A link that reads as a bare number says whose, which month and which line.
                'label' => $opens ? $this->trans('margin.table.cell', ['row' => $this->rowLabel($row->key, $row->name), 'month' => $this->monthLabel($month), 'line' => $this->trans('margin.table.line.'.$kind), 'amount' => '' === $this->amount($money) ? $this->trans('margin.table.none') : $this->amount($money)]) : null,
            ];
        }

        return ['kind' => $kind, 'label' => $this->trans('margin.table.line.'.$kind), 'cells' => $cells, 'total' => $this->amount($this->figure($row->total, $kind))];
    }

    private function figure(MarginFigures $figures, string $kind): Money
    {
        return match ($kind) {
            'revenue' => $figures->revenue,
            'costs' => $figures->costs,
            default => $figures->margin,
        };
    }

    /**
     * Whole crowns and hellers without the unit: every figure of the page is CZK, and the page says so once. Zero is empty.
     */
    private function amount(Money $money): string
    {
        return $money->isZero() ? '' : $this->money->grouped($money);
    }

    /**
     * @param list<KnownGap> $gaps
     *
     * @return ?array{text: string, documents: list<array{label: string, href: ?string}>}
     */
    private function gaps(array $gaps): ?array
    {
        $documents = [];
        foreach ($gaps as $gap) {
            foreach ($gap->documentIds as $id) {
                $document = $this->sources->document($id);
                $documents[] = ['label' => $document instanceof CostDocument ? $document->label : $id, 'href' => $document instanceof CostDocument ? $document->url : null];
            }
        }

        return [] === $documents ? null : ['text' => $this->trans('margin.table.gaps', ['count' => \count($documents)]), 'documents' => $documents];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(MarginCellItem $item, bool $assignable = false): array
    {
        $revenue = MarginCellItem::REVENUE === $item->source;

        return [
            'documentId' => $item->documentId,
            'label' => $item->label,
            'text' => $item->text,
            'href' => $item->url,
            'badge' => $revenue ? $this->trans('margin.sheet.kind.'.$item->kind) : $this->trans('margin.sheet.source.'.$item->source),
            'amount' => $this->money->shown($item->amountCzk),
            'occurrenceOn' => $item->occurrenceOn,
            // Assigning is a dialog of the Purchasing integration: offered only where that module's route exists.
            'assign' => $assignable ? $this->route(self::ASSIGN_ROUTE, ['id' => $item->documentId]) : null,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function route(string $name, array $parameters): ?string
    {
        try {
            return $this->urls->generate($name, $parameters);
        } catch (RouteNotFoundException|InvalidParameterException) {
            return null;
        }
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
