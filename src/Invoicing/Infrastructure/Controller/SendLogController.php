<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\SendLogFilter;
use Foresi\Invoicing\Application\SendLogRow;
use Foresi\Invoicing\Application\View\SendLogLists;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Infrastructure\Column\SendLogColumns;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\InvalidFilter;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every e-mail an invoice was sent with, newest first, for staff: when, to whom,
 * how it went and why it failed. The log runs on saved views as every list does
 * (spec D7): All, Sent, Waiting and Failed ship as views, with the person's own
 * beside them; the state and the type, an invoice or a reminder, are conditions
 * of the filter sheet (D11). The number leads to the invoice's history, the icon to
 * sending again: the invoice for an invoice, the next reminder for a reminder.
 *
 * The old ?status= opens the view of that name, as the tabs it replaced did, and
 * an old ?purpose= adds its condition, so bookmarks and the shell's notices still
 * narrow (D12).
 */
final class SendLogController extends AbstractController
{
    public const string ROUTE = 'app_invoice_sends';
    /** The parameter of the old tabs, which named the view. */
    private const string OLD_TAB = 'status';
    private const int MAX_SEARCH = 100;
    /** The parameter of the old select, by the field it now names. */
    private const array OLD_PARAMS = ['purpose' => SendLogLists::PURPOSE];

    public function __construct(private readonly SendLogLists $sendLogLists,
        private readonly InvoiceSends $sends,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
        private readonly SendLogColumns $columns,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/invoices/sent', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff see sent invoices.');
        }
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->sendViews($input);
        $list = $this->sendLogLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_SEARCH));
        $page = $this->sends->page(new SendLogFilter($shown, max(1, $input->getInt('page', 1)), $text));
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The quick filters, columns and density changed in the Settings sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(SendLogColumns::LIST, [], $list->key, $view, $input->all(), filters: $list);
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, '', '', $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);

        return $this->render('@Invoicing/send_log.html.twig', [
            'page' => $page,
            'text' => $text,
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), pinned: $working->pinned),
            ...$bar,
            ...$this->viewTabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table(),
            'rows' => array_map(fn (SendLogRow $row): array => $this->row($row, $working), $page->rows),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'baseUrl' => $this->generateUrl(self::ROUTE, $query),
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
            'nothingSent' => !$page->anySent,
            'filtered' => '' !== $text || null !== $filterParam,
        ]);
    }

    /**
     * The log's views as its address names them (spec D10): the person's views, the one
     * ?view= names (or ?status=, the address of the tabs the log had), All when none,
     * and the filter the log shows, the view's own unless f= changed it. An old
     * ?purpose= adds its condition; a value the field does not take narrows nothing,
     * as the select ignored it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function sendViews(InputBag $input): array
    {
        $list = $this->sendLogLists->list();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString(self::OLD_TAB)));
        $working = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        foreach (self::OLD_PARAMS as $param => $field) {
            $value = $input->getString($param);
            if ('' === $value) {
                continue;
            }
            $condition = new Condition($field, 'is', $value);
            try {
                ViewFilter::all($condition)->check($list);
                $working = $working->with($condition);
            } catch (InvalidFilter) {
                // A value the field does not take narrows nothing, as the select ignored it.
            }
        }

        return [$views, $view, $working];
    }

    /**
     * The log's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own. The old
     * parameters are in the rows by then.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->sendViews($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'page' => true, 'view' => true, self::OLD_TAB => true] + self::OLD_PARAMS);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->sendLogLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The tabs of the log and the other views of its Settings sheet (spec D7): All and the primary views
     * as tabs, the other views in the menu. The log counts none (D9).
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function viewTabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * The Settings sheet: what it keeps of the log, and the log without the changed columns.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SendLogRow $row, WorkingState $working): array
    {
        $reminder = SendPurpose::Reminder === $row->purpose;
        $id = ['id' => $row->invoiceId];

        return [
            'id' => $row->sendId,
            'href' => $this->generateUrl('app_invoice_history', $id),
            // The history of a send is a small record: it opens in a modal over the log (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'label' => $row->documentNumber,
            'secondary' => implode(' · ', array_filter([$reminder ? $this->columns->purpose($row) : null, $this->columns->when($row), $this->columns->recipients($row), $this->columns->error($row)], static fn (?string $part): bool => null !== $part && '' !== $part)),
            'cells' => $working->display->cells($row),
            'actions' => [
                $reminder
                    ? ['label' => $this->translator->trans('reminder.log.again'), 'href' => $this->generateUrl('app_invoice_remind', $id), 'method' => 'GET', 'icon' => 'bell-ring', 'dialog' => true]
                    : ['label' => $this->translator->trans('invoice.sends.action.again'), 'href' => $this->generateUrl('app_invoice_send', $id), 'method' => 'GET', 'icon' => 'send', 'dialog' => true],
            ],
        ];
    }
}
