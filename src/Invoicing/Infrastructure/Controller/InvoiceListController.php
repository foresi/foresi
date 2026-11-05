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
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuePaymentTaxDocument;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\MissingTaxDocument;
use App\Invoicing\Application\MissingTaxDocuments;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\SendMark;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\InvalidDeletionReason;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\SendStatus;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Invoicing\Infrastructure\Form\MailTokenLabels;
use App\Invoicing\Infrastructure\Form\SendFailureText;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;
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
 * is a plain page. Send, reminder and delete open in a dialog over the list; each is also a
 * page of its own, which works without JavaScript. An overdue row counts the reminders that
 * went out or wait to, read for the whole page in one query.
 *
 * @phpstan-type Action array{label: string, href: string, method: string, confirm?: array{title: string, text: string, accept: string}, icon?: string, plain?: bool, dialog?: bool, fields?: array<string, string>}
 */
final class InvoiceListController extends AbstractController
{
    public const string ACTION_TOKEN = 'invoice_action';
    /** The icon of each send mark: its label says the same, so it is never colour alone. */
    private const array MARK_ICONS = ['queued' => 'clock-3', 'sent' => 'send', 'read' => 'circle-check', 'failed' => 'triangle-alert'];
    private const array MARK_TONES = ['queued' => 'gray', 'sent' => 'gray', 'read' => 'green', 'failed' => 'red'];
    private const int MAX_TEXT = 100;
    /** The parts of the list's query an action carries back to it. */
    public const array LIST_QUERY = ['tab', 'firm', 'q', 'sort', 'page', 'toSend'];
    /** The tab of the advances that wait for a tax document. */
    private const string MISSING = 'needs_tax_document';
    /** The list of each kind of document, as iDoklad keeps one per kind. */
    public const array ROUTES = ['invoice' => 'app_invoices', 'advance' => 'app_advances', 'payment_tax_document' => 'app_tax_documents'];

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
        private readonly InvoiceSends $sends,
        private readonly MailTokenLabels $labels,
        private readonly SendFailureText $failures,
        private readonly InvoicePayments $payments,
        private readonly RecurringInvoices $recurring,
        private readonly TenantClock $tenantClock,
        private readonly MissingTaxDocuments $missing,
        private readonly IssuePaymentTaxDocument $taxDocuments,
    ) {
    }

    #[Route('/invoices', name: 'app_invoices', defaults: ['kind' => 'invoice'], methods: ['GET'])]
    #[Route('/advances', name: 'app_advances', defaults: ['kind' => 'advance'], methods: ['GET'])]
    #[Route('/tax-documents', name: 'app_tax_documents', defaults: ['kind' => 'payment_tax_document'], methods: ['GET'])]
    public function index(Request $request, string $kind): Response
    {
        $this->denyUnlessStaff();
        $kind = DocumentKind::from($kind);
        $route = self::ROUTES[$kind->value];
        $tab = $request->query->getString('tab', InvoiceFilter::ALL);
        $tab = \in_array($tab, InvoiceFilter::TABS, true) ? $tab : InvoiceFilter::ALL;
        $text = trim(mb_substr($request->query->getString('q'), 0, self::MAX_TEXT));
        $sort = $request->query->getString('sort', InvoiceFilter::DEFAULT_SORT);
        $sort = \in_array(ltrim($sort, '-'), InvoiceFilter::SORTS, true) ? $sort : InvoiceFilter::DEFAULT_SORT;
        $firm = $request->query->getString('firm');
        // The invoices recurring templates issued that wait to be sent, as the review notice links to them.
        $toSend = '1' === $request->query->getString('toSend');

        $page = $this->store->page(new InvoiceFilter('' === $firm ? null : $firm, $tab, $text, $sort, max(1, $request->query->getInt('page', 1)), $toSend, $kind));
        $firm = isset($page->firms[$firm]) ? $firm : '';
        $query = array_filter(['tab' => InvoiceFilter::ALL === $tab ? null : $tab, 'firm' => '' === $firm ? null : $firm, 'q' => '' === $text ? null : $text, 'sort' => InvoiceFilter::DEFAULT_SORT === $sort ? null : $sort, 'toSend' => $toSend ? '1' : null], static fn (?string $value): bool => null !== $value);
        // Within the firm the list is filtered by, so the chip counts what pressing it shows.
        $toSendCount = $this->recurring->inReview('' === $firm ? null : $firm);
        // What an action carries back names the kind, so it returns to this list.
        $here = (1 === $page->page ? $query : $query + ['page' => (string) $page->page]) + (DocumentKind::Invoice === $kind ? [] : ['kind' => $kind->value]);
        $severalFirms = \count($page->firms) > 1;
        $names = InvoiceFilter::TRASH === $tab ? $this->users->namesOf($this->deletedBy($page)) : [];
        $reminders = InvoiceFilter::TRASH === $tab ? [] : $this->sends->remindersFor(array_values(array_map(
            static fn (InvoiceRow $row): string => $row->id,
            array_filter($page->rows, static fn (InvoiceRow $row): bool => $row->overdue),
        )));

        return $this->render('invoicing/index.html.twig', [
            'firstRun' => [] === $page->firms,
            'page' => $page,
            'tab' => $tab,
            'text' => $text,
            'sort' => $sort,
            'firm' => $firm,
            'firms' => $severalFirms ? ['' => $this->translator->trans('invoice.list.all_firms')] + $page->firms : [],
            'kind' => $kind->value,
            'route' => $route,
            'titleKey' => 'invoice.list.title_'.$kind->value,
            'newHref' => $kind->isIssuedByPerson() ? $this->generateUrl('app_invoice_new', DocumentKind::Invoice === $kind ? [] : ['kind' => $kind->value]) : null,
            'tabs' => $this->tabs($page, $tab, $query, $route, DocumentKind::Advance === $kind ? \count($this->missing->on($this->today())) : null),
            'columns' => $this->columns($tab, $sort, $query, $severalFirms, $route),
            'rows' => array_map(fn (InvoiceRow $row): array => $this->row($row, $tab, $severalFirms, $names, http_build_query($here), $reminders[$row->id] ?? null), $page->rows),
            'baseUrl' => $this->generateUrl($route, $query),
            'toSend' => $toSend,
            'toSendCount' => $toSendCount,
            'toSendHref' => $this->generateUrl($route, $toSend ? array_diff_key($query, ['toSend' => true]) : $query + ['toSend' => '1']),
            'filtered' => '' !== $text || '' !== $firm || $toSend,
        ]);
    }

    /**
     * The paid advance invoices that wait for a tax document, soonest deadline first.
     */
    #[Route('/advances/needs-tax-document', name: 'app_advances_missing', methods: ['GET'])]
    public function missing(): Response
    {
        $this->denyUnlessStaff();
        $page = $this->store->page(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: DocumentKind::Advance));
        $missing = $this->missing->on($this->today());
        $date = $this->translator->trans('format.date');
        $columns = array_map(fn (string $key): array => ['key' => $key, 'label' => $this->translator->trans('advance.missing.column.'.$key), 'align' => \in_array($key, ['received', 'daysLeft'], true) ? 'right' : 'left', 'phone' => \in_array($key, ['number', 'daysLeft'], true)], ['number', 'customer', 'receivedOn', 'received', 'deadline', 'daysLeft']);

        return $this->render('invoicing/missing_tax_documents.html.twig', [
            'tabs' => $this->tabs($page, self::MISSING, [], self::ROUTES[DocumentKind::Advance->value], \count($missing)),
            'columns' => $columns,
            'rows' => array_map(fn (MissingTaxDocument $row): array => [
                'id' => $row->advanceId.'-'.$row->paymentId,
                'href' => $this->generateUrl('app_invoice_edit', ['id' => $row->advanceId]),
                'plain' => true,
                'label' => $row->advanceNumber,
                'secondary' => $this->joined([$row->customerName, $this->amounts->shown($row->received), $this->daysLeft($row)]),
                'cells' => [
                    'number' => $row->advanceNumber,
                    'customer' => $row->customerName,
                    'receivedOn' => $row->receivedOn->format($date),
                    'received' => $this->amounts->shown($row->received),
                    'deadline' => $row->deadline->format($date),
                    'daysLeft' => ['text' => $this->daysLeft($row), 'tone' => $row->isLate() ? 'red' : ($row->daysLeft <= 3 ? 'amber' : 'gray')],
                ],
                'actions' => [[
                    'label' => $this->translator->trans('advance.missing.issue'),
                    'href' => $this->generateUrl('app_advance_tax_document', ['id' => $row->advanceId, 'payment' => $row->paymentId]),
                    'method' => 'POST',
                    'icon' => 'file-signature',
                ]],
            ], $missing),
        ]);
    }

    /**
     * Issues the tax document a payment of an advance is still waiting for.
     */
    #[Route('/advances/{id}/payments/{payment}/tax-document', name: 'app_advance_tax_document', requirements: ['id' => Requirement::UUID_V7, 'payment' => Requirement::UUID_V7], methods: ['POST'])]
    public function issueTaxDocument(string $id, string $payment, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->invoice($id);
        $this->checkToken($request);
        try {
            $document = $this->taxDocuments->byHand($id, $payment);
        } catch (IssueRefused $refused) {
            foreach ($refused->problems as $problem) {
                $this->addFlash('error', $this->translator->trans($problem->key, $problem->parameters));
            }

            return $this->redirectToRoute('app_advances_missing');
        }
        $this->addFlash('success', $this->translator->trans('advance.missing.issued', ['number' => $document->number()]));

        return $this->redirectToRoute('app_advances_missing');
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
        $reason = $request->request->getString('reason');
        try {
            $saved = $this->trash->trash($id, $reason);
        } catch (InvalidDeletionReason $refused) {
            $problem = $refused->isMissing() ? 'invoice.list.reason_missing' : 'invoice.problem.too_long';

            return $this->trashForm($this->invoice($id), $reason, $this->translator->trans($problem), $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PaymentRefused) {
            $this->addFlash('error', $this->translator->trans('invoice.payment.refused.has_payments'));

            return $this->toList($return);
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
        $invoice = $this->invoice($id);
        $this->checkToken($request);
        if (!$invoice->isTrashed()) {
            $this->addFlash('warning', $this->translator->trans('invoice.list.not_in_trash'));
        } else {
            $this->addFlash('success', $this->translator->trans('invoice.flash.restored', ['number' => $this->trash->restore($id)->number()]));
        }

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

        $timezone = $this->timezoneOf($invoice);
        $changes = array_map(fn (InvoiceHistoryEntry $entry): array => $this->timelineItem($entry, $timezone, $invoice->currency()), $this->history->of($invoice));

        return $this->render('invoicing/history.html.twig', [
            'invoice' => $invoice,
            'badge' => $this->badge($invoice),
            'timeline' => $this->inTime($changes, $this->sendItems($invoice, $timezone, InvoiceStatus::Overdue === InvoiceStatus::ofInvoice($invoice, $this->today(), $this->payments->paidOf($invoice)))),
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
            'payable' => $this->amounts->shown($invoice->payable()),
            'maxReason' => Invoice::MAX_REASON_LENGTH,
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
        [$route, $parameters] = self::routeOf($query);

        return $this->generateUrl($route, $parameters);
    }

    /**
     * The list a query that travelled through the browser comes back to: the route of
     * its kind of document, with only the known parts of the query.
     *
     * @param array<mixed> $query
     *
     * @return array{string, array<string, string>}
     */
    public static function routeOf(array $query): array
    {
        $kind = DocumentKind::tryFrom(\is_string($query['kind'] ?? null) ? $query['kind'] : '') ?? DocumentKind::Invoice;
        /** @var array<string, string> $parameters */
        $parameters = array_filter(array_intersect_key($query, array_flip(self::LIST_QUERY)), \is_string(...));

        return [self::ROUTES[$kind->value], $parameters];
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
    private function tabs(InvoicePage $page, string $tab, array $query, string $route, ?int $missing = null): array
    {
        $tabs = [];
        foreach (InvoiceFilter::TABS as $key) {
            $tabs[] = [
                'label' => $this->translator->trans('invoice.list.tab.'.$key),
                'href' => $this->generateUrl($route, ['tab' => InvoiceFilter::ALL === $key ? null : $key] + $query),
                'count' => $page->tabCounts[$key],
                'active' => $key === $tab,
            ];
        }
        // The advances that wait for a tax document: their own list, as iDoklad gives tax documents theirs.
        if (null !== $missing) {
            array_splice($tabs, \count($tabs) - 1, 0, [[
                'label' => $this->translator->trans('advance.missing.tab'),
                'href' => $this->generateUrl('app_advances_missing'),
                'count' => $missing,
                'active' => self::MISSING === $tab,
            ]]);
        }

        return $tabs;
    }

    /**
     * The names and the reason give way to the numbers, dates, states and actions, cut short
     * with their full text in a title, so the table fits its frame with every column shown. A
     * narrower screen leaves out the issue date and the e-mail mark, the columns of least value
     * to a list read for what to collect; the invoice still shows both.
     *
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function columns(string $tab, string $sort, array $query, bool $severalFirms, string $route): array
    {
        $trash = InvoiceFilter::TRASH === $tab;
        $columns = [$this->column('number', $sort, $query, sortable: true, route: $route)];
        if ($severalFirms) {
            $columns[] = $this->column('firm', $sort, $query, phone: false) + ['truncate' => true];
        }
        $columns[] = $this->column('customer', $sort, $query, sortable: true, phone: false, route: $route) + ['truncate' => true];
        if ($trash) {
            $columns[] = $this->column('deletedBy', $sort, $query, phone: false) + ['truncate' => true];
            $columns[] = $this->column('deletedAt', $sort, $query, phone: false);
            $columns[] = $this->column('reason', $sort, $query, phone: false) + ['truncate' => true];
        } else {
            $columns[] = $this->column('issueDate', $sort, $query, sortable: true, phone: false, route: $route) + ['wide' => true];
            $columns[] = $this->column('dueDate', $sort, $query, sortable: true, phone: false, route: $route);
        }
        $columns[] = $this->column('payable', $sort, $query, sortable: true, align: 'right', route: $route);
        $columns[] = $this->column('status', $sort, $query, phone: false);
        if (!$trash) {
            $columns[] = $this->column('sent', $sort, $query, phone: false) + ['wide' => true];
        }

        return $columns;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function column(string $key, string $sort, array $query, bool $sortable = false, string $align = 'left', bool $phone = true, string $route = 'app_invoices'): array
    {
        $column = ['key' => $key, 'label' => $this->translator->trans('invoice.list.column.'.$key), 'align' => $align, 'phone' => $phone];
        if (!$sortable) {
            return $column;
        }
        $next = $sort === $key ? '-'.$key : $key;

        return $column + [
            'sortable' => true,
            'sort' => $sort === $key ? 'asc' : ($sort === '-'.$key ? 'desc' : null),
            'href' => $this->generateUrl($route, ['sort' => $next] + $query),
        ];
    }

    /**
     * @param array<string, string>                              $names     by user id
     * @param array{count: int, lastAt: \DateTimeImmutable}|null $reminders of an overdue row
     *
     * @return array<string, mixed>
     */
    private function row(InvoiceRow $row, string $tab, bool $severalFirms, array $names, string $return, ?array $reminders = null): array
    {
        $date = $this->translator->trans('format.date');
        $status = InvoiceStatus::of($row->delivered, $row->overdue, $row->trashed, $row->payment);
        $badge = $this->translator->trans('invoice.list.status.'.$status->value);
        // A part payment says what is left; overdue or not, that is what someone acts on.
        $left = PaymentState::PartlyPaid === $row->payment && !$row->trashed ? $this->translator->trans('invoice.payment.left', ['amount' => $this->amounts->shown($row->payable->minus($row->paid))]) : null;
        $cells = ['number' => $row->number, 'customer' => $row->customerName, 'payable' => $this->amounts->shown($row->payable), 'status' => ['text' => $badge, 'tone' => $status->tone()] + (null === $left ? [] : ['hint' => $left])];
        $badge = null === $left ? $badge : $badge.' · '.$left;
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
            $overdue = $this->translator->trans('invoice.list.status.overdue');
            $mark = SendMark::None === $row->sendMark ? null : $this->translator->trans('invoice.mark.'.$row->sendMark->value);
            $sent = null === $mark ? null : ['text' => $mark, 'icon' => self::MARK_ICONS[$row->sendMark->value], 'tone' => self::MARK_TONES[$row->sendMark->value], 'hint' => $this->translator->trans('invoice.mark.hint.'.$row->sendMark->value)];
            // A reminded row says how many reminders went, in the place of the mark: the newest
            // e-mail was one of them. A failure keeps its mark, as that is what to act on.
            if (null !== $reminders && SendMark::Failed !== $row->sendMark) {
                $last = $this->translator->trans('reminder.list.last', ['date' => $reminders['lastAt']->setTimezone($this->tenantClock->timezone())->format($date)]);
                $sent = [
                    'text' => $this->translator->trans('reminder.list.count', ['count' => $reminders['count']]),
                    'icon' => 'bell-ring',
                    'tone' => SendMark::Read === $row->sendMark ? 'green' : 'gray',
                    'hint' => $this->joined([$last, $mark]),
                ];
                $mark = $sent['text'];
            }
            $cells += [
                'issueDate' => $row->issueDate->format($date),
                'dueDate' => $row->overdue ? ['text' => $due, 'tone' => 'red', 'hint' => $overdue] : $due,
                'sent' => $sent,
            ];
            $secondary = $this->joined([$row->customerName, $due, $badge, $mark]);
        }

        return [
            'id' => $row->id,
            'href' => $this->generateUrl('app_invoice_edit', ['id' => $row->id]),
            'plain' => true,
            'label' => $row->number,
            'secondary' => $secondary,
            'cells' => $cells,
            'actions' => $this->actions($row, $return, $reminders['count'] ?? 0),
        ];
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function badge(Invoice $invoice): array
    {
        $status = InvoiceStatus::ofInvoice($invoice, $this->today(), $this->payments->paidOf($invoice));

        return ['label' => $this->translator->trans('invoice.list.status.'.$status->value), 'tone' => $status->tone()];
    }

    /**
     * The tenant's day, as the reminder run counts it (ADR 0017): whether an invoice is overdue.
     */
    private function today(): \DateTimeImmutable
    {
        return $this->tenantClock->today();
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
    private function actions(InvoiceRow $row, string $return, int $reminders): array
    {
        $id = ['id' => $row->id];
        $fields = ['return' => $return];
        $pdf = ['label' => $this->label('pdf'), 'href' => $this->generateUrl('app_invoice_pdf', $id), 'method' => 'GET', 'icon' => 'file-down', 'plain' => true];
        $copy = ['label' => $this->label('copy'), 'href' => $this->generateUrl('app_invoice_copy', $id), 'method' => 'GET', 'plain' => true];
        $history = ['label' => $this->label('history'), 'href' => $this->generateUrl('app_invoice_history', $id), 'method' => 'GET'];
        if ($row->trashed) {
            return array_values(array_filter([
                ['label' => $this->label('restore'), 'href' => $this->generateUrl('app_invoice_restore', $id), 'method' => 'POST', 'icon' => 'archive-restore', 'fields' => $fields],
                $pdf,
                $row->kind->isIssuedByPerson() ? $copy : null,
                $history,
            ]));
        }

        $send = ['label' => $this->label('send'), 'href' => $this->generateUrl('app_invoice_send', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        $recurring = ['label' => $this->translator->trans('recurring.form.from_invoice'), 'href' => $this->generateUrl('app_recurring_new', ['from' => $row->id]), 'method' => 'GET', 'plain' => true];
        $actions = [['label' => $this->label('edit'), 'href' => $this->generateUrl('app_invoice_edit', $id), 'method' => 'GET', 'icon' => 'pencil', 'plain' => true], $pdf];
        // Offered while something is still to pay, as iDoklad's Pay is.
        if ($row->kind->isPayable() && !$row->payment->isSettled() && !$row->payable->isZero() && !$row->payable->isNegative()) {
            $actions[] = ['label' => $this->label('pay'), 'href' => $this->generateUrl('app_invoice_pay', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'icon' => 'banknote', 'dialog' => true];
        }
        $actions[] = $send;
        // Reminders of other statuses still take a step, so the dialog may yet refuse the tenth; it says why.
        if ($row->overdue && $reminders < FirmReminders::MAX_STEPS) {
            $actions[] = ['label' => $this->translator->trans('reminder.list.action'), 'href' => $this->generateUrl('app_invoice_remind', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        }
        // A tax document on payment comes from its payment only: nothing is issued from it.
        if ($row->kind->isIssuedByPerson()) {
            $actions[] = $copy;
            $actions[] = $recurring;
        }
        if (!$row->delivered) {
            $actions[] = ['label' => $this->label('delivered'), 'href' => $this->generateUrl('app_invoice_delivered', $id), 'method' => 'POST', 'confirm' => [
                'title' => $this->translator->trans('invoice.list.delivered_confirm', ['number' => $row->number]),
                'text' => $this->translator->trans('invoice.list.delivered_confirm_text'),
                'accept' => $this->translator->trans('invoice.list.delivered_confirm_accept'),
            ], 'fields' => $fields];
        }
        $actions[] = $history;
        if (!$row->delivered) {
            $actions[] = ['label' => $this->label('delete'), 'href' => $this->generateUrl('app_invoice_trash', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        }

        return $actions;
    }

    private function daysLeft(MissingTaxDocument $row): string
    {
        return $row->isLate()
            ? $this->translator->trans('advance.missing.days_late', ['days' => -$row->daysLeft])
            : $this->translator->trans('advance.missing.days_left', ['days' => $row->daysLeft]);
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
    private function timelineItem(InvoiceHistoryEntry $entry, \DateTimeZone $timezone, Currency $currency): array
    {
        $who = $entry->userName ?? $this->translator->trans('invoice.history.system');
        $amount = $this->amounts->shown(Money::ofMinor($entry->amountMinor ?? 0, $currency));
        $text = $this->translator->trans('invoice.history.'.$entry->event, ['who' => $who, 'reason' => $entry->reason ?? '', 'amount' => $amount]);
        $changes = [...array_map($this->fieldLabel(...), $entry->fields), ...array_map($this->lineChange(...), $entry->lines)];

        return [
            'time' => $entry->occurredAt->setTimezone($timezone)->format($this->translator->trans('format.datetime')),
            'datetime' => $entry->occurredAt->format(\DATE_ATOM),
            'icon' => match ($entry->event) {
                InvoiceHistoryEntry::CREATED => 'plus',
                InvoiceHistoryEntry::ISSUED_FROM_RECURRING => 'refresh-cw',
                InvoiceHistoryEntry::TRASHED => 'trash-2',
                InvoiceHistoryEntry::RESTORED => 'archive-restore',
                InvoiceHistoryEntry::DELIVERED => 'circle-check',
                InvoiceHistoryEntry::PAID => 'banknote',
                InvoiceHistoryEntry::PAYMENT_CANCELLED => 'banknote-x',
                default => 'pencil',
            },
            'text' => [] === $changes ? $text : $text.': '.implode('; ', $changes),
        ];
    }

    /**
     * The changes and the sends in one line of time. At the same moment the creation
     * comes first, then a send, then what the send caused, such as its delivery.
     *
     * @param list<array<string, mixed>> $changes
     * @param list<array<string, mixed>> $sends
     *
     * @return list<array<string, mixed>>
     */
    private function inTime(array $changes, array $sends): array
    {
        $items = [...$changes, ...$sends];
        $key = static fn (array $item): array => [
            new \DateTimeImmutable(\is_string($item['datetime']) ? $item['datetime'] : throw new \LogicException('An item of the history needs its moment.')),
            isset($item['send']) ? 1 : ('plus' === $item['icon'] ? 0 : 2),
        ];
        usort($items, static fn (array $one, array $other): int => $key($one) <=> $key($other));

        return $items;
    }

    /**
     * Each send of the invoice as the history shows it, a reminder with its step. Only the
     * newest offers to send again: the invoice after an invoice, and after a reminder the
     * next reminder while the invoice is overdue and has steps left.
     *
     * @return list<array<string, mixed>>
     */
    private function sendItems(Invoice $invoice, \DateTimeZone $timezone, bool $overdue): array
    {
        $sends = $this->sends->ofInvoice($invoice);
        $names = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (InvoiceSend $send): ?string => $send->createdBy(), $sends), \is_string(...)))));
        $format = $this->translator->trans('format.datetime');
        $items = [];
        foreach ($sends as $index => $send) {
            $recipients = implode(', ', [...$send->to(), ...$send->cc()]);
            $copy = $send->copyTo();
            $status = $send->status()->value;
            $reminder = SendPurpose::Reminder === $send->purpose();
            $newest = $index === array_key_last($sends) && !$invoice->isTrashed();
            $again = null;
            if ($newest && !$reminder) {
                $again = $this->generateUrl('app_invoice_send', ['id' => $invoice->id()]);
            } elseif ($newest && $overdue && ($send->reminderStep() ?? 0) < FirmReminders::MAX_STEPS) {
                $again = $this->generateUrl('app_invoice_remind', ['id' => $invoice->id()]);
            }
            $parameters = ['who' => $this->who($send->createdBy(), $names), 'recipients' => $recipients];
            $items[] = [
                'send' => true,
                'time' => $send->createdAt()->setTimezone($timezone)->format($format),
                'datetime' => $send->createdAt()->format(\DATE_ATOM),
                'icon' => $reminder ? 'bell-ring' : 'send',
                'text' => ($reminder ? $this->translator->trans('reminder.history.sent', $parameters + ['step' => $send->reminderStep() ?? 0]) : $this->translator->trans('invoice.history.send', $parameters))
                    .(null === $copy ? '' : ' · '.$this->translator->trans('invoice.history.send_copy', ['address' => $copy])),
                'status' => $this->translator->trans('invoice.sends.status.'.$status),
                'tone' => match ($send->status()) {
                    SendStatus::Sent => 'green',
                    SendStatus::Failed => 'red',
                    default => 'gray',
                },
                'read' => null === $send->firstReadAt() ? null : $this->translator->trans('invoice.history.send_read', ['date' => $send->firstReadAt()->setTimezone($timezone)->format($format)]),
                'error' => SendStatus::Sent === $send->status() ? null : $this->failures->of($send->lastErrorCode(), $send->lastErrorSmtp(), $send->lastError()),
                'copyError' => $this->failures->of($send->copyErrorCode(), $send->copyErrorSmtp(), $send->copyError()),
                'subject' => $send->subject(),
                'body' => $this->labels->linkToEditor($send->body(), $send->language()),
                'language' => $send->language()->value,
                'pdf' => $this->generateUrl('app_invoice_sent_pdf', ['id' => $invoice->id(), 'sendId' => $send->id()]),
                'again' => $again,
                'againLabel' => $this->translator->trans($reminder ? 'reminder.history.again' : 'invoice.history.send_again'),
            ];
        }

        return $items;
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
        $text = null === $line->previousText ? $line->text : $line->previousText.' → '.$line->text;
        $parameters = ['number' => $line->number, 'text' => $text];
        if (InvoiceHistoryLine::CHANGED !== $line->change) {
            return $this->translator->trans('invoice.history.line_'.$line->change, $parameters);
        }
        if ([] === $line->fields) {
            return $this->translator->trans('invoice.history.line_text', $parameters);
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
