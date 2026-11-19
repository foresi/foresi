<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\MissingTaxDocument;
use Foresi\Invoicing\Application\UncorrectedTaxDocument;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueues;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Infrastructure\Column\TaxDocumentQueueColumns;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Invoicing\Infrastructure\View\TaxDocumentQueueTabs;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two lists a tax document waits in, with the controls of every list
 * (docs/design/list-pages.md): their views as tabs, the search, "+ Filter", the
 * Columns sheet, Save, Save as new and Reset. The VAT rules work out their rows,
 * so the views filter them in memory (RowConditions); they are few, a list of
 * work with a deadline, soonest first.
 */
final class TaxDocumentQueueController extends AbstractController
{
    public const string DEFAULT_SORT = 'deadline';
    public const array SORTS = ['deadline', 'number', 'customer'];
    private const int MAX_TEXT = 100;

    public function __construct(
        private readonly TaxDocumentQueues $queues,
        private readonly InstanceClock $clock,
        private readonly ListViews $views,
        private readonly AuditActor $actor,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
        private readonly TaxDocumentQueueColumns $columns,
        private readonly AmountText $amounts,
        private readonly TranslatorInterface $translator,
        private readonly TaxDocumentQueueTabs $queueTabs,
    ) {
    }

    /**
     * The payments of advances that wait for a tax document, soonest deadline first.
     */
    #[Route('/advances/needs-tax-document', name: 'app_advances_missing', methods: ['GET'])]
    public function missing(Request $request): Response
    {
        return $this->queue($request, TaxDocumentQueueLists::missing(), 'app_advances_missing', DocumentKind::Advance, [
            'parent' => ['label' => 'invoice.list.title_advance', 'route' => 'app_advances'],
            'prefix' => 'advance.missing',
        ]);
    }

    /**
     * The delivered tax documents on payment that wait for a corrective one, soonest deadline first.
     */
    #[Route('/tax-documents/needs-correction', name: 'app_tax_documents_uncorrected', methods: ['GET'])]
    public function uncorrected(Request $request): Response
    {
        return $this->queue($request, TaxDocumentQueueLists::uncorrected(), 'app_tax_documents_uncorrected', DocumentKind::PaymentTaxDocument, [
            'parent' => ['label' => 'invoice.list.title_payment_tax_document', 'route' => 'app_tax_documents'],
            'prefix' => 'tax_document.uncorrected',
        ]);
    }

    /**
     * @param array{parent: array{label: string, route: string}, prefix: string} $page
     */
    private function queue(Request $request, ListDefinition $list, string $route, DocumentKind $kind, array $page): Response
    {
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute($route, $this->builtQuery($input, $list), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->viewsOf($input, $list);
        $filterParam = WorkingFilter::param($shown, $view->filter);
        // A view keeps its own order; the address names the sort only when it differs (spec D10).
        $viewSort = ViewState::sortParam($view->sort, self::DEFAULT_SORT);
        $sort = $input->getString('sort', $viewSort);
        $sort = \in_array(ltrim($sort, '-'), self::SORTS, true) ? $sort : self::DEFAULT_SORT;
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_TEXT));

        $today = $this->clock->today();
        $searched = TaxDocumentQueues::searched($this->queues->rows($list->key, $today), $text);
        $rows = $this->sorted(TaxDocumentQueues::matching($searched, $list->key, $shown, $today), $sort);
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The columns and density changed in the Columns sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read($list->key, [], $list->key, $view, $input->all());
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, self::DEFAULT_SORT, $working, $route, '' === $text ? [] : ['q' => $text]);

        return $this->render('@Invoicing/tax_document_queue.html.twig', [
            'page' => $page,
            'route' => $route,
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl($route, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true]))),
            'text' => $text,
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            ...$this->tabs($views, $view, $query, $route, $kind, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl($route, ['sort' => $next] + $query)),
            'rows' => array_map(fn (MissingTaxDocument|UncorrectedTaxDocument $row): array => $this->row($row, $working), $rows),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query, $route),
            'workingQuery' => $working->query,
            'filtered' => '' !== $text || null !== $filterParam,
        ]);
    }

    /**
     * The list's views, the one ?view= names (or ?tab=), its All when none, and the
     * filter the list shows, the view's own unless f= changed it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function viewsOf(InputBag $input, ListDefinition $list): array
    {
        $views = $this->views->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input, ListDefinition $list): array
    {
        [, $view] = $this->viewsOf($input, $list);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true]);

        return array_filter($query + [WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $list), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The rows in the order asked for; the rules give them by deadline, which breaks a tie.
     *
     * @param list<MissingTaxDocument|UncorrectedTaxDocument> $rows
     *
     * @return list<MissingTaxDocument|UncorrectedTaxDocument>
     */
    private function sorted(array $rows, string $sort): array
    {
        $key = ltrim($sort, '-');
        $sign = str_starts_with($sort, '-') ? -1 : 1;
        $value = match ($key) {
            'number' => TaxDocumentQueues::number(...),
            'customer' => static fn (MissingTaxDocument|UncorrectedTaxDocument $row): string => mb_strtolower($row->customerName),
            default => static fn (MissingTaxDocument|UncorrectedTaxDocument $row): string => $row->deadline->format('Y-m-d'),
        };
        // usort is stable, so equal values keep the deadline order the rules gave.
        usort($rows, static fn (MissingTaxDocument|UncorrectedTaxDocument $a, MissingTaxDocument|UncorrectedTaxDocument $b): int => $sign * strnatcmp($value($a), $value($b)));

        return $rows;
    }

    /**
     * The tabs of the list the queue belongs to, with the queue's tab active while it
     * shows its All (A1): the queue reads as a tab of the advances or of the tax
     * documents. The views saved on the queue follow the list's other views under
     * "More views", where the view bar's Save as new puts them.
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, ListView $active, array $query, string $route, DocumentKind $kind, bool $modified): array
    {
        $parent = $this->queueTabs->parentOf($kind, $this->actor->userId(), $active->isAll(), $modified && $active->isAll());
        foreach ($views as $view) {
            if ($view->isAll()) {
                continue;
            }
            $parent['moreViews'][] = [
                'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
                // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
                'href' => $this->generateUrl($route, ['view' => $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
                'active' => $view->key === $active->key,
                'modified' => $modified && $view->key === $active->key,
                'private' => $view->private,
            ];
        }

        return $parent;
    }

    /**
     * One row: it opens the document in the sheet, and offers what clears it.
     *
     * @return array<string, mixed>
     */
    private function row(MissingTaxDocument|UncorrectedTaxDocument $row, WorkingState $working): array
    {
        $missing = $row instanceof MissingTaxDocument;
        $id = $missing ? $row->advanceId : $row->documentId;

        return [
            'id' => $missing ? $row->advanceId.'-'.$row->paymentId : $row->documentId,
            'href' => $this->generateUrl('app_invoice_show', ['id' => $id]),
            'opens' => 'sheet',
            'label' => TaxDocumentQueues::number($row),
            'secondary' => implode(' · ', array_filter([$row->customerName, $this->amounts->shown($missing ? $row->received : $row->amount), $this->columns->daysLeft($row->daysLeft)], static fn (string $part): bool => '' !== $part)),
            'cells' => $working->display->cells($row),
            'actions' => [$missing ? [
                'label' => $this->translator->trans('advance.missing.issue'),
                'href' => $this->generateUrl('app_advance_tax_document', ['id' => $row->advanceId, 'payment' => $row->paymentId]),
                'method' => 'POST',
                'icon' => 'file-signature',
            ] : [
                'label' => $this->translator->trans('tax_document.correct'),
                'href' => $this->generateUrl('app_invoice_correct', ['id' => $row->documentId]),
                'icon' => 'file-signature',
            ]],
        ];
    }

    /**
     * The Columns sheet: what it keeps of the list, and the list without the changed columns.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query, string $route): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl($route, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }
}
