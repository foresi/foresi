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
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\SendMark;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\InvalidDeletionReason;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendStatus;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Invoicing\Infrastructure\Form\MailTokenLabels;
use App\Invoicing\Infrastructure\Form\SendFailureText;
use Psr\Clock\ClockInterface;
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
 * is a plain page. Send and delete open in a dialog over the list; each is also a page of
 * its own, which works without JavaScript.
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
    public const array LIST_QUERY = ['tab', 'firm', 'q', 'sort', 'page'];

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
        private readonly ClockInterface $clock,
        private readonly InvoiceSends $sends,
        private readonly MailTokenLabels $labels,
        private readonly SendFailureText $failures,
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
        $reason = $request->request->getString('reason');
        try {
            $saved = $this->trash->trash($id, $reason);
        } catch (InvalidDeletionReason $refused) {
            $problem = $refused->isMissing() ? 'invoice.list.reason_missing' : 'invoice.problem.too_long';

            return $this->trashForm($this->invoice($id), $reason, $this->translator->trans($problem), $return, Response::HTTP_UNPROCESSABLE_ENTITY);
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
        $changes = array_map(fn (InvoiceHistoryEntry $entry): array => $this->timelineItem($entry, $timezone), $this->history->of($invoice));

        return $this->render('invoicing/history.html.twig', [
            'invoice' => $invoice,
            'badge' => $this->badge($invoice),
            'timeline' => $this->inTime($changes, $this->sendItems($invoice, $timezone)),
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
        $columns[] = $this->column('status', $sort, $query, phone: false);
        if (!$trash) {
            $columns[] = $this->column('sent', $sort, $query, phone: false);
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
        $status = InvoiceStatus::of($row->delivered, $row->overdue, $row->trashed);
        $badge = $this->translator->trans('invoice.list.status.'.$status->value);
        $cells = ['number' => $row->number, 'customer' => $row->customerName, 'payable' => $this->amounts->shown($row->payable), 'status' => ['text' => $badge, 'tone' => $status->tone()]];
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
            $cells += [
                'issueDate' => $row->issueDate->format($date),
                'dueDate' => $row->overdue ? ['text' => $due, 'tone' => 'red', 'hint' => $overdue] : $due,
                'sent' => null === $mark ? null : ['text' => $mark, 'icon' => self::MARK_ICONS[$row->sendMark->value], 'tone' => self::MARK_TONES[$row->sendMark->value], 'hint' => $this->translator->trans('invoice.mark.hint.'.$row->sendMark->value)],
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
            'actions' => $this->actions($row, $return),
        ];
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function badge(Invoice $invoice): array
    {
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($this->timezoneOf($invoice));
        $status = InvoiceStatus::ofInvoice($invoice, $today);

        return ['label' => $this->translator->trans('invoice.list.status.'.$status->value), 'tone' => $status->tone()];
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
        $pdf = ['label' => $this->label('pdf'), 'href' => $this->generateUrl('app_invoice_pdf', $id), 'method' => 'GET', 'icon' => 'file-down', 'plain' => true];
        $copy = ['label' => $this->label('copy'), 'href' => $this->generateUrl('app_invoice_copy', $id), 'method' => 'GET', 'plain' => true];
        $history = ['label' => $this->label('history'), 'href' => $this->generateUrl('app_invoice_history', $id), 'method' => 'GET'];
        if ($row->trashed) {
            return [
                ['label' => $this->label('restore'), 'href' => $this->generateUrl('app_invoice_restore', $id), 'method' => 'POST', 'icon' => 'archive-restore', 'fields' => $fields],
                $pdf,
                $copy,
                $history,
            ];
        }

        $send = ['label' => $this->label('send'), 'href' => $this->generateUrl('app_invoice_send', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        $actions = [['label' => $this->label('edit'), 'href' => $this->generateUrl('app_invoice_edit', $id), 'method' => 'GET', 'icon' => 'pencil', 'plain' => true], $pdf, $send, $copy];
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
                InvoiceHistoryEntry::ISSUED_FROM_RECURRING => 'refresh-cw',
                InvoiceHistoryEntry::TRASHED => 'trash-2',
                InvoiceHistoryEntry::RESTORED => 'archive-restore',
                InvoiceHistoryEntry::DELIVERED => 'circle-check',
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
     * Each send of the invoice as the history shows it. Only the newest offers to send again.
     *
     * @return list<array<string, mixed>>
     */
    private function sendItems(Invoice $invoice, \DateTimeZone $timezone): array
    {
        $sends = $this->sends->ofInvoice($invoice);
        $names = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (InvoiceSend $send): ?string => $send->createdBy(), $sends), \is_string(...)))));
        $format = $this->translator->trans('format.datetime');
        $items = [];
        foreach ($sends as $index => $send) {
            $recipients = implode(', ', [...$send->to(), ...$send->cc()]);
            $copy = $send->copyTo();
            $status = $send->status()->value;
            $items[] = [
                'send' => true,
                'time' => $send->createdAt()->setTimezone($timezone)->format($format),
                'datetime' => $send->createdAt()->format(\DATE_ATOM),
                'icon' => 'send',
                'text' => $this->translator->trans('invoice.history.send', ['who' => $this->who($send->createdBy(), $names), 'recipients' => $recipients])
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
                'again' => $index === array_key_last($sends) && !$invoice->isTrashed() ? $this->generateUrl('app_invoice_send', ['id' => $invoice->id()]) : null,
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
