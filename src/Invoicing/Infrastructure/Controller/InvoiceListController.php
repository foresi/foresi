<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Identity\Application\UserRepository;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\DocumentExports;
use Foresi\Invoicing\Application\DocumentMonths;
use Foresi\Invoicing\Application\ExportDocumentList;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\InvoiceHistoryEntry;
use Foresi\Invoicing\Application\InvoiceHistoryLine;
use Foresi\Invoicing\Application\InvoicePage;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\InvoiceStatus;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\IssuePaymentTaxDocument;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\ListFileFormat;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\MissingTaxDocument;
use Foresi\Invoicing\Application\MissingTaxDocuments;
use Foresi\Invoicing\Application\PaymentRefused;
use Foresi\Invoicing\Application\Query\InvoiceDetails;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Application\UncorrectedTaxDocument;
use Foresi\Invoicing\Application\UncorrectedTaxDocuments;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Invoicing\Domain\ExportState;
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Invoicing\Domain\InvalidDeletionReason;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceSend;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Domain\SendStatus;
use Foresi\Invoicing\Infrastructure\Column\IssuedDocumentColumns;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Invoicing\Infrastructure\Form\MailTokenLabels;
use Foresi\Invoicing\Infrastructure\Form\SendFailureText;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Column\ListDisplay;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoice list, its trash and the history of a document, for staff. An action on a
 * row comes back to the list it started from. A row opens the document's detail in the
 * sheet (docs/design/overlays.md); links into the form leave Turbo: the form page is plain. Send, reminder and delete open in a dialog over the list; each is also a
 * page of its own, which works without JavaScript. An overdue row counts the reminders that
 * went out or wait to, read for the whole page in one query.
 *
 * @phpstan-type Action array{label: string, href: string, method: string, confirm?: array{title: string, text: string, accept: string}, icon?: string, plain?: bool, dialog?: bool, fields?: array<string, string>}
 */
final class InvoiceListController extends AbstractController
{
    public const string ACTION_TOKEN = 'invoice_action';
    private const int MAX_TEXT = 100;
    /** The parts of the list's query an action carries back to it. */
    public const array LIST_QUERY = ['tab', 'firm', 'q', 'sort', 'page', 'toSend', 'export', 'month'];
    /** The tab of the advances that wait for a tax document. */
    private const string MISSING = 'needs_tax_document';
    /** The tab of the tax documents on payment that wait for a corrective one. */
    private const string UNCORRECTED = 'needs_correction';
    /** The list of each kind of document, as iDoklad keeps one per kind. */
    public const array ROUTES = ['invoice' => 'app_invoices', 'advance' => 'app_advances', 'payment_tax_document' => 'app_tax_documents', 'correction_document' => 'app_corrections'];

    public function __construct(
        private readonly InvoiceStore $store,
        private readonly TrashInvoice $trash,
        private readonly MarkDelivered $delivered,
        private readonly InvoicingCountries $countries,
        private readonly UserRepository $users,
        private readonly AmountText $amounts,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
        private readonly InvoiceSends $sends,
        private readonly MailTokenLabels $labels,
        private readonly SendFailureText $failures,
        private readonly RecurringInvoices $recurring,
        private readonly InstanceClock $instanceClock,
        private readonly MissingTaxDocuments $missing,
        private readonly IssuePaymentTaxDocument $taxDocuments,
        private readonly UncorrectedTaxDocuments $uncorrected,
        private readonly DocumentExports $exports,
        private readonly ExportDocumentList $listExport,
        private readonly DocumentMonths $months,
        private readonly InvoiceList $list,
        private readonly InvoiceDetails $details,
        private readonly ListColumnRegistry $listColumns,
    ) {
    }

    #[Route('/invoices', name: 'app_invoices', defaults: ['kind' => 'invoice'], methods: ['GET'])]
    #[Route('/advances', name: 'app_advances', defaults: ['kind' => 'advance'], methods: ['GET'])]
    #[Route('/tax-documents', name: 'app_tax_documents', defaults: ['kind' => 'payment_tax_document'], methods: ['GET'])]
    #[Route('/corrections', name: 'app_corrections', defaults: ['kind' => 'correction_document'], methods: ['GET'])]
    public function index(Request $request, string $kind): Response
    {
        $kind = DocumentKind::from($kind);
        $route = self::ROUTES[$kind->value];
        $filter = $this->filterOf($request->query, $kind);
        [$tab, $text, $sort, $firm, $toSend, $export, $month] = [$filter->tab, $filter->text, $filter->sort, $filter->firmId ?? '', $filter->toSend, $filter->export, $filter->month];
        $page = $this->list->page($filter);
        $firm = isset($page->firms[$firm]) ? $firm : '';
        $query = array_filter(['tab' => InvoiceFilter::ALL === $tab ? null : $tab, 'firm' => '' === $firm ? null : $firm, 'q' => '' === $text ? null : $text, 'sort' => InvoiceFilter::DEFAULT_SORT === $sort ? null : $sort, 'toSend' => $toSend ? '1' : null, 'export' => $export?->value, 'month' => $month], static fn (?string $value): bool => null !== $value);
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
            'export' => $export->value ?? '',
            'exportStates' => ['' => $this->translator->trans('invoice.export_state.all')] + array_combine(
                array_map(static fn (ExportState $state): string => $state->value, ExportState::cases()),
                array_map(fn (ExportState $state): string => $this->translator->trans('invoice.export_state.'.$state->value), ExportState::cases()),
            ),
            'kind' => $kind->value,
            'route' => $route,
            'titleKey' => 'invoice.list.title_'.$kind->value,
            // A corrective document is issued from the document it corrects, never from a blank form.
            'newHref' => $kind->standsAlone() ? $this->generateUrl('app_invoice_new', DocumentKind::Invoice === $kind ? [] : ['kind' => $kind->value]) : null,
            'tabs' => $this->tabs($page, $tab, $query, $route, DocumentKind::Advance === $kind ? \count($this->missing->on($this->today())) : null, DocumentKind::PaymentTaxDocument === $kind ? \count($this->uncorrected->on($this->today())) : null),
            'columns' => $this->columns($tab, $sort, $query, $severalFirms, $route),
            'rows' => array_map(fn (InvoiceRow $row): array => $this->row($row, $tab, $severalFirms, $names, http_build_query($here), $reminders[$row->id] ?? null), $page->rows),
            'baseUrl' => $this->generateUrl($route, $query),
            'toSend' => $toSend,
            'toSendCount' => $toSendCount,
            'toSendHref' => $this->generateUrl($route, $toSend ? array_diff_key($query, ['toSend' => true]) : $query + ['toSend' => '1']),
            'month' => $month ?? '',
            // The months with a document of the list, and the one asked for, though it has none.
            'months' => ['' => $this->translator->trans('invoice.month.all')] + MonthChoices::of([...$this->months->months($kind, '' === $firm ? null : $firm), ...(null === $month ? [] : [$month])], $this->translator->getLocale()),
            // An empty list adds up to nothing to show, and saves the queries.
            'filtered' => '' !== $text || '' !== $firm || $toSend || $export instanceof ExportState || null !== $month,
        ]);
    }

    /**
     * Downloads exactly what the list shows, its filter posted back: the same tab, firm,
     * month, export state, text and order, over all pages. It is streamed and read in
     * batches, so a long list needs no limit. The name says the kind and the month only.
     */
    #[Route('/invoices/list-export', name: 'app_invoices_list_export', methods: ['POST'])]
    public function exportList(Request $request): StreamedResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $kind = DocumentKind::tryFrom($request->request->getString('kind')) ?? throw new NotFoundHttpException();
        $filter = $this->filterOf($request->request, $kind);
        $format = 'xlsx' === $request->request->getString('format') ? ListFileFormat::Xlsx : ListFileFormat::Csv;
        $name = \sprintf('%s_%s.%s', $this->translator->trans('invoice.export.folder.'.$kind->value), $filter->month ?? $this->translator->trans('invoice.list_export.all_months'), $format->value);

        // The request has ended by the time the response streams, and its locale with it.
        $locale = $request->getLocale();
        $response = new StreamedResponse(function () use ($filter, $format, $locale): void {
            $this->listExport->write($filter, $format, 'php://output', $locale);
        });
        $response->headers->set('Content-Type', ListFileFormat::Xlsx === $format ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The filter of a list from its query, or from the form of its download: anything
     * it does not know falls back to the whole list.
     *
     * @param InputBag<string> $input
     */
    private function filterOf(InputBag $input, DocumentKind $kind): InvoiceFilter
    {
        $tab = $input->getString('tab', InvoiceFilter::ALL);
        $sort = $input->getString('sort', InvoiceFilter::DEFAULT_SORT);
        $firm = $input->getString('firm');

        return new InvoiceFilter(
            '' === $firm ? null : $firm,
            \in_array($tab, InvoiceFilter::tabsOf($kind), true) ? $tab : InvoiceFilter::ALL,
            trim(mb_substr($input->getString('q'), 0, self::MAX_TEXT)),
            \in_array(ltrim($sort, '-'), InvoiceFilter::SORTS, true) ? $sort : InvoiceFilter::DEFAULT_SORT,
            max(1, $input->getInt('page', 1)),
            // The invoices recurring templates issued that wait to be sent, as the review notice links to them.
            '1' === $input->getString('toSend'),
            $kind,
            ExportState::tryFrom($input->getString('export')),
            InvoiceFilter::monthOf($input->getString('month')),
        );
    }

    /**
     * The paid advance invoices that wait for a tax document, soonest deadline first.
     */
    #[Route('/advances/needs-tax-document', name: 'app_advances_missing', methods: ['GET'])]
    public function missing(): Response
    {
        $page = $this->list->page(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: DocumentKind::Advance));
        $missing = $this->missing->on($this->today());
        $date = $this->translator->trans('format.date');
        $columns = array_map(fn (string $key): array => ['key' => $key, 'label' => $this->translator->trans('advance.missing.column.'.$key), 'align' => \in_array($key, ['received', 'daysLeft'], true) ? 'right' : 'left', 'phone' => \in_array($key, ['number', 'daysLeft'], true)], ['number', 'customer', 'receivedOn', 'received', 'deadline', 'daysLeft']);

        return $this->render('invoicing/missing_tax_documents.html.twig', [
            'tabs' => $this->tabs($page, self::MISSING, [], self::ROUTES[DocumentKind::Advance->value], \count($missing)),
            'columns' => $columns,
            'rows' => array_map(fn (MissingTaxDocument $row): array => [
                'id' => $row->advanceId.'-'.$row->paymentId,
                'href' => $this->generateUrl('app_invoice_show', ['id' => $row->advanceId]),
                'opens' => 'sheet',
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
     * The delivered tax documents on payment that wait for a corrective tax document,
     * soonest deadline first.
     */
    #[Route('/tax-documents/needs-correction', name: 'app_tax_documents_uncorrected', methods: ['GET'])]
    public function uncorrected(): Response
    {
        $page = $this->list->page(new InvoiceFilter(null, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: DocumentKind::PaymentTaxDocument));
        $waiting = $this->uncorrected->on($this->today());
        $date = $this->translator->trans('format.date');
        $columns = array_map(fn (string $key): array => ['key' => $key, 'label' => $this->translator->trans('tax_document.uncorrected.column.'.$key), 'align' => \in_array($key, ['amount', 'daysLeft'], true) ? 'right' : 'left', 'phone' => \in_array($key, ['number', 'daysLeft'], true)], ['number', 'customer', 'decisiveDate', 'amount', 'deadline', 'daysLeft']);

        return $this->render('invoicing/uncorrected_tax_documents.html.twig', [
            'tabs' => $this->tabs($page, self::UNCORRECTED, [], self::ROUTES[DocumentKind::PaymentTaxDocument->value], null, \count($waiting)),
            'columns' => $columns,
            'rows' => array_map(fn (UncorrectedTaxDocument $row): array => [
                'id' => $row->documentId,
                'href' => $this->generateUrl('app_invoice_show', ['id' => $row->documentId]),
                'opens' => 'sheet',
                'label' => $row->number,
                'secondary' => $this->joined([$row->customerName, $this->amounts->shown($row->amount), $this->daysLeftOf($row->daysLeft)]),
                'cells' => [
                    'number' => $row->deducted ? $row->number.' · '.$this->translator->trans('tax_document.uncorrected.deducted') : $row->number,
                    'customer' => $row->customerName,
                    'decisiveDate' => $row->decisiveDate->format($date),
                    'amount' => $this->amounts->shown($row->amount),
                    'deadline' => $row->deadline->format($date),
                    'daysLeft' => ['text' => $this->daysLeftOf($row->daysLeft), 'tone' => $row->isLate() ? 'red' : ($row->daysLeft <= 3 ? 'amber' : 'gray')],
                ],
                'actions' => [[
                    'label' => $this->translator->trans('tax_document.correct'),
                    'href' => $this->generateUrl('app_invoice_correct', ['id' => $row->documentId]),
                    'icon' => 'file-signature',
                ]],
            ], $waiting),
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

            return $this->toList($request->request->getString('return'));
        }
        try {
            $this->addFlash('success', $this->translator->trans('invoice.flash.restored', ['number' => $this->trash->restore($id)->number()]));
        } catch (PaymentRefused $refused) {
            $this->addFlash('error', $this->translator->trans('invoice.payment.refused.'.$refused->reason));
        } catch (IssueRefused $refused) {
            // Another invoice deducts an advance this one deducted, or its time changed: it stays in the trash.
            $this->addFlash('error', $this->translator->trans($refused->problems[0]->key, $refused->problems[0]->parameters));
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

    /**
     * The next pack for the accountant takes the document again.
     */
    #[Route('/invoices/{id}/clear-export', name: 'app_invoice_clear_export', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function clearExport(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        $this->checkToken($request);
        if (ExportState::NotExported === $invoice->exportState()) {
            $this->addFlash('warning', $this->translator->trans('invoice.list.not_exported'));

            return $this->toList($request->request->getString('return'));
        }
        $this->addFlash('success', $this->translator->trans('invoice.flash.export_cleared', ['number' => $this->exports->clear($id)->number()]));

        return $this->toList($request->request->getString('return'));
    }

    #[Route('/invoices/{id}/history', name: 'app_invoice_history', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function showHistory(string $id): Response
    {
        $detail = $this->details->of($id);
        $invoice = $detail->invoice;
        $status = InvoiceStatus::ofInvoice($invoice, $this->today(), $detail->paid, $detail->corrections);

        $timezone = $this->timezoneOf($invoice);
        $changes = array_map(fn (InvoiceHistoryEntry $entry): array => $this->timelineItem($entry, $timezone, $invoice->currency()), $detail->history);

        return $this->render('invoicing/history.html.twig', [
            'invoice' => $invoice,
            'badge' => ['label' => $this->translator->trans('invoice.list.status.'.$status->value), 'tone' => $status->tone()],
            'timeline' => $this->inTime($changes, $this->sendItems($invoice, $timezone, InvoiceStatus::Overdue === $status)),
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
    private function tabs(InvoicePage $page, string $tab, array $query, string $route, ?int $missing = null, ?int $uncorrected = null): array
    {
        $tabs = [];
        foreach (array_keys($page->tabCounts) as $key) {
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
        // The tax documents that wait for a corrective one, the same way.
        if (null !== $uncorrected) {
            array_splice($tabs, \count($tabs) - 1, 0, [[
                'label' => $this->translator->trans('tax_document.uncorrected.tab'),
                'href' => $this->generateUrl('app_tax_documents_uncorrected'),
                'count' => $uncorrected,
                'active' => self::UNCORRECTED === $tab,
            ]]);
        }

        return $tabs;
    }

    /**
     * The columns of the list as IssuedDocumentColumns declares them for the tab.
     *
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function columns(string $tab, string $sort, array $query, bool $severalFirms, string $route): array
    {
        return $this->column($tab, $severalFirms)->table($sort, fn (string $next): string => $this->generateUrl($route, ['sort' => $next] + $query));
    }

    /**
     * How the list shows for the tab: the registry's default columns (no view gives a display yet).
     *
     * @param array<string, string>                                        $names     by user id
     * @param array<string, array{count: int, lastAt: \DateTimeImmutable}> $reminders by the id of an overdue row
     */
    private function column(string $tab, bool $severalFirms, array $names = [], array $reminders = []): ListDisplay
    {
        return $this->listColumns->display(IssuedDocumentColumns::LIST, ['trash' => InvoiceFilter::TRASH === $tab, 'severalFirms' => $severalFirms, 'names' => $names, 'reminders' => $reminders]);
    }

    /**
     * A row of the list; a phone shows the customer, the dates and the marks in the line under
     * the number, as the cells read.
     *
     * @param array<string, string>                              $names     by user id
     * @param array{count: int, lastAt: \DateTimeImmutable}|null $reminders of an overdue row
     *
     * @return array<string, mixed>
     */
    private function row(InvoiceRow $row, string $tab, bool $severalFirms, array $names, string $return, ?array $reminders = null): array
    {
        $cells = $this->column($tab, $severalFirms, $names, null === $reminders ? [] : [$row->id => $reminders])->cells($row);
        $text = static function (string $key, string $part = 'text') use ($cells): ?string {
            $value = \is_array($cells[$key] ?? null) ? ($cells[$key][$part] ?? null) : ('text' === $part ? ($cells[$key] ?? null) : null);

            return \is_string($value) ? $value : null;
        };
        $status = $this->joined([$text('status'), $text('status', 'hint')]);
        $secondary = InvoiceFilter::TRASH === $tab
            ? $this->joined([$row->customerName, $text('deletedBy'), $text('deletedAt'), $text('reason')])
            : $this->joined([$row->customerName, $text('dueDate'), $status, $text('sent'), $text('export')]);

        return [
            'id' => $row->id,
            'href' => $this->generateUrl('app_invoice_show', ['id' => $row->id]),
            'opens' => 'sheet',
            'label' => $row->number,
            'secondary' => $secondary,
            'cells' => $cells,
            'actions' => $this->actions($row, $return, $reminders['count'] ?? 0),
        ];
    }

    /**
     * The instance's day, as the reminder run counts it (ADR 0017): whether an invoice is overdue.
     */
    private function today(): \DateTimeImmutable
    {
        return $this->instanceClock->today();
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
                $row->kind->standsAlone() ? $copy : null,
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
        // A tax document on payment or a correction follows another document: nothing is issued from it.
        if ($row->kind->standsAlone()) {
            $actions[] = $copy;
            $actions[] = $recurring;
        }
        // A delivered tax document on payment is corrected by hand as well, a moved advance say.
        if (DocumentKind::Invoice === $row->kind || (DocumentKind::PaymentTaxDocument === $row->kind && $row->delivered)) {
            $actions[] = ['label' => $this->label('correct'), 'href' => $this->generateUrl('app_invoice_correct', $id), 'method' => 'GET', 'plain' => true];
        }
        if (!$row->delivered) {
            $actions[] = ['label' => $this->label('delivered'), 'href' => $this->generateUrl('app_invoice_delivered', $id), 'method' => 'POST', 'confirm' => [
                'title' => $this->translator->trans('invoice.list.delivered_confirm', ['number' => $row->number]),
                'text' => $this->translator->trans('invoice.list.delivered_confirm_text'),
                'accept' => $this->translator->trans('invoice.list.delivered_confirm_accept'),
            ], 'fields' => $fields];
        }
        if (ExportState::NotExported !== $row->exportState()) {
            $actions[] = ['label' => $this->label('clear_export'), 'href' => $this->generateUrl('app_invoice_clear_export', $id), 'method' => 'POST', 'fields' => $fields];
        }
        $actions[] = $history;
        if (!$row->delivered) {
            $actions[] = ['label' => $this->label('delete'), 'href' => $this->generateUrl('app_invoice_trash', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        }

        return $actions;
    }

    private function daysLeft(MissingTaxDocument $row): string
    {
        return $this->daysLeftOf($row->daysLeft);
    }

    private function daysLeftOf(int $days): string
    {
        return $days < 0
            ? $this->translator->trans('advance.missing.days_late', ['days' => -$days])
            : $this->translator->trans('advance.missing.days_left', ['days' => $days]);
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
                InvoiceHistoryEntry::EXPORTED => 'archive',
                InvoiceHistoryEntry::EXPORT_CLEARED => 'archive-restore',
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
