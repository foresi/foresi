<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\SendLogFilter;
use App\Invoicing\Application\SendLogRow;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\SendStatus;
use App\Invoicing\Infrastructure\Form\SendFailureText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every e-mail an invoice was sent with, newest first, for staff: when, from which
 * firm, to whom, how it went and why it failed. A tab per state, and a firm when
 * there are several, and invoices or reminders. The number leads to the
 * invoice's history, the icon to sending again: the invoice for an invoice, the
 * next reminder for a reminder.
 * Times are in the time zone of the country that issued the invoice.
 */
final class SendLogController extends AbstractController
{
    private const string ALL = 'all';
    private const array TABS = [self::ALL, 'sent', 'queued', 'failed'];
    private const array TONES = ['queued' => 'gray', 'sent' => 'green', 'failed' => 'red', 'cancelled' => 'gray'];

    public function __construct(
        private readonly InvoiceSends $sends,
        private readonly InvoicingCountries $countries,
        private readonly SendFailureText $failures,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/invoices/sent', name: 'app_invoice_sends', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff see sent invoices.');
        }
        $status = \in_array($request->query->getString('status'), self::TABS, true) ? SendStatus::tryFrom($request->query->getString('status')) : null;
        $purpose = SendPurpose::tryFrom($request->query->getString('purpose'));
        $firm = $request->query->getString('firm');
        $page = $this->sends->page(new SendLogFilter('' === $firm ? null : $firm, $status, max(1, $request->query->getInt('page', 1)), $purpose));
        $firm = isset($page->firms[$firm]) ? $firm : '';
        $query = array_filter(['status' => $status?->value, 'firm' => '' === $firm ? null : $firm, 'purpose' => $purpose?->value], static fn (?string $value): bool => null !== $value);
        $severalFirms = \count($page->firms) > 1;

        return $this->render('invoicing/send_log.html.twig', [
            'page' => $page,
            'status' => $status->value ?? self::ALL,
            'firm' => $firm,
            'firms' => $severalFirms ? ['' => $this->translator->trans('invoice.list.all_firms')] + $page->firms : [],
            'purpose' => $purpose->value ?? '',
            'purposes' => ['' => $this->translator->trans('reminder.log.all')] + array_combine(
                array_map(static fn (SendPurpose $case): string => $case->value, SendPurpose::cases()),
                array_map(fn (SendPurpose $case): string => $this->translator->trans('reminder.log.purpose.'.$case->value), SendPurpose::cases()),
            ),
            'tabs' => $this->tabs($status->value ?? self::ALL, $firm, $purpose),
            'columns' => $this->columns($severalFirms),
            'rows' => array_map(fn (SendLogRow $row): array => $this->row($row, $severalFirms), $page->rows),
            'baseUrl' => $this->generateUrl('app_invoice_sends', $query),
            'filtered' => [] !== $query,
            'nothingSent' => [] === $page->firms,
        ]);
    }

    /**
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function tabs(string $status, string $firm, ?SendPurpose $purpose): array
    {
        $tabs = [];
        foreach (self::TABS as $key) {
            $tabs[] = [
                'label' => $this->translator->trans('invoice.sends.tab.'.$key),
                'href' => $this->generateUrl('app_invoice_sends', array_filter(['status' => self::ALL === $key ? null : $key, 'firm' => '' === $firm ? null : $firm, 'purpose' => $purpose?->value], static fn (?string $value): bool => null !== $value)),
                'active' => $key === $status,
            ];
        }

        return $tabs;
    }

    /**
     * The names, addresses and errors give way, cut short with their full text in a title, so
     * the table fits its frame.
     *
     * @return list<array{key: string, label: string, phone: bool, truncate: bool}>
     */
    private function columns(bool $severalFirms): array
    {
        $keys = ['number' => true, 'when' => false, 'purpose' => false];
        if ($severalFirms) {
            $keys['firm'] = false;
        }
        $keys += ['customer' => false, 'recipients' => false, 'status' => true, 'error' => false];
        $columns = [];
        foreach ($keys as $key => $phone) {
            $label = 'purpose' === $key ? 'reminder.log.column' : 'invoice.sends.column.'.$key;
            $truncate = \in_array($key, ['firm', 'customer', 'recipients', 'error'], true);
            $columns[] = ['key' => $key, 'label' => $this->translator->trans($label), 'phone' => $phone, 'truncate' => $truncate];
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SendLogRow $row, bool $severalFirms): array
    {
        $timezone = $this->timezone($row->issuerCountry);
        $format = $this->translator->trans('format.datetime');
        $when = $row->createdAt->setTimezone($timezone)->format($format);
        $read = $row->readAt?->setTimezone($timezone)->format($format);
        // An opened send says so in its status, with the moment it was first opened.
        $status = null === $read
            ? ['text' => $this->translator->trans('invoice.sends.status.'.$row->status->value), 'tone' => self::TONES[$row->status->value]]
            : ['text' => $this->translator->trans('invoice.history.send_read', ['date' => $read]), 'tone' => 'green'];
        $recipients = implode(', ', $row->recipients);
        $error = $this->failures->of($row->errorCode, $row->errorSmtp, $row->error);
        $reminder = SendPurpose::Reminder === $row->purpose;
        $purpose = $reminder ? $this->translator->trans('reminder.log.step', ['step' => $row->reminderStep ?? 0]) : $this->translator->trans('reminder.log.purpose.invoice');
        $cells = [
            'number' => $row->documentNumber,
            'when' => $when,
            'purpose' => ['text' => $purpose, 'icon' => $reminder ? 'bell-ring' : 'receipt-text', 'tone' => 'gray'],
            'customer' => $row->customerName,
            'recipients' => $recipients,
            'status' => $status,
            'error' => $error ?? '',
        ];
        if ($severalFirms) {
            $cells['firm'] = $row->firmName;
        }
        $id = ['id' => $row->invoiceId];

        return [
            'id' => $row->sendId,
            'href' => $this->generateUrl('app_invoice_history', $id),
            'label' => $row->documentNumber,
            'secondary' => implode(' · ', array_filter([$reminder ? $purpose : null, $when, $recipients, $error], static fn (?string $part): bool => null !== $part && '' !== $part)),
            'cells' => $cells,
            'actions' => [
                $reminder
                    ? ['label' => $this->translator->trans('reminder.log.again'), 'href' => $this->generateUrl('app_invoice_remind', $id), 'method' => 'GET', 'icon' => 'bell-ring', 'dialog' => true]
                    : ['label' => $this->translator->trans('invoice.sends.action.again'), 'href' => $this->generateUrl('app_invoice_send', $id), 'method' => 'GET', 'icon' => 'send', 'dialog' => true],
            ],
        ];
    }

    private function timezone(string $country): \DateTimeZone
    {
        try {
            return $this->countries->for($country)->timezone();
        } catch (UnsupportedCountry) {
            return new \DateTimeZone('UTC');
        }
    }
}
