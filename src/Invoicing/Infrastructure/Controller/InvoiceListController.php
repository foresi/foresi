<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Identity\Application\UserRepository;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceHistory;
use App\Invoicing\Application\InvoiceHistoryEntry;
use App\Invoicing\Application\InvoiceHistoryLine;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Infrastructure\Form\AmountText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoice list, its trash and the history of a document, for staff. An action on a
 * row comes back to the list it started from. Links into the form leave Turbo: the form
 * is a plain page. A delete asks for its reason on a page of its own, which works with
 * the keyboard, on a phone and without JavaScript.
 *
 * @phpstan-type Action array{label: string, href: string, method: string, confirm?: string, icon?: string, plain?: bool, fields?: array<string, string>}
 */
final class InvoiceListController extends AbstractController
{
    public const string ACTION_TOKEN = 'invoice_action';
    private const int MAX_TEXT = 100;
    private const int MAX_REASON = 500;
    private const int MAX_LINE_TEXT = 40;
    private const array LIST_QUERY = ['tab', 'firm', 'q', 'sort', 'page'];

    public function __construct(
        private readonly InvoiceStore $store,
        private readonly TrashInvoice $trash,
        private readonly MarkDelivered $delivered,
        private readonly InvoiceHistory $history,
        private readonly InvoicingCountries $countries,
        private readonly UserRepository $users,
        private readonly AmountText $amounts,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/invoices', name: 'app_invoices', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $tab = $request->query->getString('tab', InvoiceFilter::ALL);
        $tab = \in_array($tab, InvoiceFilter::TABS, true) ? $tab : InvoiceFilter::ALL;
        $text = trim(mb_substr($request->query->getString('q'), 0, self::MAX_TEXT));
        $sort = $request->query->getString('sort', InvoiceFilter::DEFAULT_SORT);
        $sort = \in_array(ltrim($sort, '-'), InvoiceFilter::SORTS, true) ? $sort : InvoiceFilter::DEFAULT_SORT;
        $firm = $request->query->getString('firm');

        $page = $this->store->page(new InvoiceFilter('' === $firm ? null : $firm, $tab, $text, $sort, max(1, $request->query->getInt('page', 1))));
        $firm = isset($page->firms[$firm]) ? $firm : '';
        $query = array_filter(['tab' => InvoiceFilter::ALL === $tab ? null : $tab, 'firm' => '' === $firm ? null : $firm, 'q' => '' === $text ? null : $text, 'sort' => InvoiceFilter::DEFAULT_SORT === $sort ? null : $sort], static fn (?string $value): bool => null !== $value);
        $here = 1 === $page->page ? $query : $query + ['page' => (string) $page->page];
        $severalFirms = \count($page->firms) > 1;
        $names = InvoiceFilter::TRASH === $tab ? $this->users->namesOf($this->deletedBy($page)) : [];

        return $this->render('invoicing/index.html.twig', [
            'firstRun' => [] === $page->firms,
            'page' => $page,
            'tab' => $tab,
            'text' => $text,
            'sort' => $sort,
            'firm' => $firm,
            'firms' => $severalFirms ? ['' => $this->translator->trans('invoice.list.all_firms')] + $page->firms : [],
            'tabs' => $this->tabs($page, $tab, $query),
            'columns' => $this->columns($tab, $sort, $query, $severalFirms),
            'rows' => array_map(fn (InvoiceRow $row): array => $this->row($row, $tab, $severalFirms, $names, http_build_query($here)), $page->rows),
            'baseUrl' => $this->generateUrl('app_invoices', $query),
            'filtered' => '' !== $text || '' !== $firm,
        ]);
    }

    #[Route('/invoices/{id}/trash', name: 'app_invoice_trash', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function trash(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        $return = $request->isMethod('POST') ? $request->request->getString('return') : $request->query->getString('return');
        if (!$request->isMethod('POST')) {
            return $invoice->isDelivered() || $invoice->isTrashed() ? $this->refused($invoice, $return) : $this->trashForm($invoice, '', null, $return, Response::HTTP_OK);
        }

        $this->checkToken($request);
        $reason = trim($request->request->getString('reason'));
        $problem = match (true) {
            '' === $reason => 'invoice.list.reason_missing',
            mb_strlen($reason) > self::MAX_REASON => 'invoice.problem.too_long',
            default => null,
        };
        if (null !== $problem) {
            return $this->trashForm($invoice, $reason, $this->translator->trans($problem), $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saved = $this->trash->trash($id, $reason);
        } catch (\DomainException) {
            return $this->refused($this->invoice($id), $return);
        }
        $this->addFlash('success', $this->translator->trans('invoice.flash.trashed', ['number' => $saved->number()]));

        return $this->toList($return);
    }

    #[Route('/invoices/{id}/restore', name: 'app_invoice_restore', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->invoice($id);
        $this->checkToken($request);
        $this->addFlash('success', $this->translator->trans('invoice.flash.restored', ['number' => $this->trash->restore($id)->number()]));

        return $this->toList($request->request->getString('return'));
    }

    #[Route('/invoices/{id}/delivered', name: 'app_invoice_delivered', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function markDelivered(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->invoice($id);
        $this->checkToken($request);
        $return = $request->request->getString('return');
        try {
            $saved = $this->delivered->mark($id);
        } catch (\DomainException) {
            $this->addFlash('error', $this->translator->trans('invoice.list.delivered_trashed'));

            return $this->toList($return);
        }
        $this->addFlash('success', $this->translator->trans('invoice.flash.delivered', ['number' => $saved->number()]));

        return $this->toList($return);
    }

    #[Route('/invoices/{id}/history', name: 'app_invoice_history', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function showHistory(string $id): Response
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);

        return $this->render('invoicing/history.html.twig', [
            'invoice' => $invoice,
            'timeline' => array_map(fn (InvoiceHistoryEntry $entry): array => $this->timelineItem($entry, $this->timezoneOf($invoice)), $this->history->of($invoice)),
        ]);
    }

    private function refused(Invoice $invoice, string $return): RedirectResponse
    {
        $this->addFlash('error', $this->translator->trans($invoice->isDelivered() ? 'invoice.list.delete_delivered' : 'invoice.list.already_trashed'));

        return $this->toList($return);
    }

    private function trashForm(Invoice $invoice, string $reason, ?string $error, string $return, int $status): Response
    {
        return $this->render('invoicing/trash.html.twig', [
            'invoice' => $invoice,
            'reason' => $reason,
            'error' => $error,
            'return' => $return,
            'cancel' => $this->listUrl($return),
            'payable' => $this->amounts->money($invoice->payable()),
            'maxReason' => self::MAX_REASON,
        ], new Response(status: $status));
    }

    private function toList(string $return): RedirectResponse
    {
        return $this->redirect($this->listUrl($return));
    }

    /**
     * Only the known parts of the query come back: the value travelled through the browser.
     */
    private function listUrl(string $return): string
    {
        parse_str($return, $query);

        return $this->generateUrl('app_invoices', array_filter(
            array_intersect_key($query, array_flip(self::LIST_QUERY)),
            \is_string(...),
        ));
    }

    /**
     * @return list<string>
     */
    private function deletedBy(InvoicePage $page): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (InvoiceRow $row): ?string => $row->deletedBy, $page->rows), \is_string(...))));
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function tabs(InvoicePage $page, string $tab, array $query): array
    {
        $tabs = [];
        foreach (InvoiceFilter::TABS as $key) {
            $tabs[] = [
                'label' => $this->translator->trans('invoice.list.tab.'.$key),
                'href' => $this->generateUrl('app_invoices', ['tab' => InvoiceFilter::ALL === $key ? null : $key] + $query),
                'count' => $page->tabCounts[$key],
                'active' => $key === $tab,
            ];
        }

        return $tabs;
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function columns(string $tab, string $sort, array $query, bool $severalFirms): array
    {
        $trash = InvoiceFilter::TRASH === $tab;
        $columns = [$this->column('number', $sort, $query, sortable: true)];
        if ($severalFirms) {
            $columns[] = $this->column('firm', $sort, $query, phone: false);
        }
        $columns[] = $this->column('customer', $sort, $query, sortable: true, phone: false);
        if ($trash) {
            $columns[] = $this->column('deletedBy', $sort, $query, phone: false);
            $columns[] = $this->column('deletedAt', $sort, $query, phone: false);
            $columns[] = $this->column('reason', $sort, $query, phone: false);
        } else {
            $columns[] = $this->column('issueDate', $sort, $query, sortable: true, phone: false);
            $columns[] = $this->column('dueDate', $sort, $query, sortable: true, phone: false);
        }
        $columns[] = $this->column('payable', $sort, $query, sortable: true, align: 'right');
        if (!$trash) {
            $columns[] = $this->column('status', $sort, $query, phone: false);
        }

        return $columns;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function column(string $key, string $sort, array $query, bool $sortable = false, string $align = 'left', bool $phone = true): array
    {
        $column = ['key' => $key, 'label' => $this->translator->trans('invoice.list.column.'.$key), 'align' => $align, 'phone' => $phone];
        if (!$sortable) {
            return $column;
        }
        $next = $sort === $key ? '-'.$key : $key;

        return $column + [
            'sortable' => true,
            'sort' => $sort === $key ? 'asc' : ($sort === '-'.$key ? 'desc' : null),
            'href' => $this->generateUrl('app_invoices', ['sort' => $next] + $query),
        ];
    }

    /**
     * @param array<string, string> $names by user id
     *
     * @return array<string, mixed>
     */
    private function row(InvoiceRow $row, string $tab, bool $severalFirms, array $names, string $return): array
    {
        $date = $this->translator->trans('format.date');
        $cells = ['number' => $row->number, 'customer' => $row->customerName, 'payable' => $this->amounts->money($row->payable)];
        if ($severalFirms) {
            $cells['firm'] = $row->firmName;
        }
        if (InvoiceFilter::TRASH === $tab) {
            $who = $this->who($row->deletedBy, $names);
            $when = $row->deletedAt?->format($this->translator->trans('format.datetime')) ?? '';
            $cells += ['deletedBy' => $who, 'deletedAt' => $when, 'reason' => $row->deletedReason];
            $secondary = $this->joined([$row->customerName, $who, $when, $row->deletedReason]);
        } else {
            $due = $row->dueDate->format($date);
            $status = $this->translator->trans('invoice.list.status.delivered');
            $cells += [
                'issueDate' => $row->issueDate->format($date),
                'dueDate' => $row->overdue ? ['text' => $due, 'tone' => 'red', 'hint' => $this->translator->trans('invoice.list.status.overdue')] : $due,
                'status' => $row->delivered ? ['text' => $status, 'tone' => 'green'] : null,
            ];
            $secondary = $this->joined([$row->customerName, $due, $row->delivered ? $status : null]);
        }

        return [
            'id' => $row->id,
            'href' => $this->generateUrl('app_invoice_edit', ['id' => $row->id]),
            'plain' => true,
            'label' => $row->number,
            'secondary' => $secondary,
            'cells' => $cells,
            'actions' => $this->actions($row, $return),
        ];
    }

    /**
     * @param list<string|null> $parts
     */
    private function joined(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (?string $part): bool => null !== $part && '' !== $part));
    }

    /**
     * @return list<Action>
     */
    private function actions(InvoiceRow $row, string $return): array
    {
        $id = ['id' => $row->id];
        $fields = ['return' => $return];
        $history = ['label' => $this->label('history'), 'href' => $this->generateUrl('app_invoice_history', $id), 'method' => 'GET'];
        if ($row->trashed) {
            return [
                ['label' => $this->label('restore'), 'href' => $this->generateUrl('app_invoice_restore', $id), 'method' => 'POST', 'icon' => 'archive-restore', 'fields' => $fields],
                $history,
            ];
        }

        $actions = [['label' => $this->label('edit'), 'href' => $this->generateUrl('app_invoice_edit', $id), 'method' => 'GET', 'icon' => 'pencil', 'plain' => true]];
        if (!$row->delivered) {
            $actions[] = ['label' => $this->label('delivered'), 'href' => $this->generateUrl('app_invoice_delivered', $id), 'method' => 'POST', 'confirm' => $this->translator->trans('invoice.list.delivered_confirm', ['number' => $row->number]), 'fields' => $fields];
        }
        $actions[] = $history;
        if (!$row->delivered) {
            $actions[] = ['label' => $this->label('delete'), 'href' => $this->generateUrl('app_invoice_trash', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET'];
        }

        return $actions;
    }

    private function label(string $action): string
    {
        return $this->translator->trans('invoice.list.action.'.$action);
    }

    /**
     * @param array<string, string> $names by user id
     */
    private function who(?string $userId, array $names): string
    {
        return $names[$userId ?? ''] ?? $this->translator->trans('invoice.history.system');
    }

    /**
     * @return array{time: string, datetime: string, icon: string, text: string}
     */
    private function timelineItem(InvoiceHistoryEntry $entry, \DateTimeZone $timezone): array
    {
        $who = $entry->userName ?? $this->translator->trans('invoice.history.system');
        $text = $this->translator->trans('invoice.history.'.$entry->event, ['who' => $who, 'reason' => $entry->reason ?? '']);
        $changes = [...array_map($this->fieldLabel(...), $entry->fields), ...array_map($this->lineChange(...), $entry->lines)];

        return [
            'time' => $entry->occurredAt->setTimezone($timezone)->format($this->translator->trans('format.datetime')),
            'datetime' => $entry->occurredAt->format(\DATE_ATOM),
            'icon' => match ($entry->event) {
                InvoiceHistoryEntry::CREATED => 'plus',
                InvoiceHistoryEntry::TRASHED => 'trash-2',
                InvoiceHistoryEntry::RESTORED => 'archive-restore',
                InvoiceHistoryEntry::DELIVERED => 'circle-check',
                default => 'pencil',
            },
            'text' => [] === $changes ? $text : $text.': '.implode('; ', $changes),
        ];
    }

    private function timezoneOf(Invoice $invoice): \DateTimeZone
    {
        try {
            return $this->countries->for($invoice->issuer()->address->countryCode)->timezone();
        } catch (UnsupportedCountry) {
            return new \DateTimeZone('UTC');
        }
    }

    private function fieldLabel(string $field): string
    {
        return $this->translator->trans('invoice.history.field.'.$field);
    }

    private function lineChange(InvoiceHistoryLine $line): string
    {
        $parameters = ['number' => $line->number, 'text' => mb_strimwidth($line->text, 0, self::MAX_LINE_TEXT, '…')];
        if (InvoiceHistoryLine::CHANGED !== $line->change) {
            return $this->translator->trans('invoice.history.line_'.$line->change, $parameters);
        }

        return $this->translator->trans('invoice.history.line_changed', $parameters + ['fields' => implode(', ', array_map($this->fieldLabel(...), $line->fields))]);
    }

    private function invoice(string $id): Invoice
    {
        try {
            return Uuid::isValid($id) ? $this->store->get($id) : throw new NotFoundHttpException();
        } catch (UnknownInvoice) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with invoices.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
