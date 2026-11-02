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
 * there are several. The number leads to the invoice's history, the icon to
 * sending again.
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
        $firm = $request->query->getString('firm');
        $page = $this->sends->page(new SendLogFilter('' === $firm ? null : $firm, $status, max(1, $request->query->getInt('page', 1))));
        $firm = isset($page->firms[$firm]) ? $firm : '';
        $query = array_filter(['status' => $status?->value, 'firm' => '' === $firm ? null : $firm], static fn (?string $value): bool => null !== $value);
        $severalFirms = \count($page->firms) > 1;

        return $this->render('invoicing/send_log.html.twig', [
            'page' => $page,
            'status' => $status->value ?? self::ALL,
            'firm' => $firm,
            'firms' => $severalFirms ? ['' => $this->translator->trans('invoice.list.all_firms')] + $page->firms : [],
            'tabs' => $this->tabs($status->value ?? self::ALL, $firm),
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
    private function tabs(string $status, string $firm): array
    {
        $tabs = [];
        foreach (self::TABS as $key) {
            $tabs[] = [
                'label' => $this->translator->trans('invoice.sends.tab.'.$key),
                'href' => $this->generateUrl('app_invoice_sends', array_filter(['status' => self::ALL === $key ? null : $key, 'firm' => '' === $firm ? null : $firm], static fn (?string $value): bool => null !== $value)),
                'active' => $key === $status,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array{key: string, label: string, phone: bool}>
     */
    private function columns(bool $severalFirms): array
    {
        $keys = ['number' => true, 'when' => false];
        if ($severalFirms) {
            $keys['firm'] = false;
        }
        $keys += ['customer' => false, 'recipients' => false, 'status' => true, 'error' => false];
        $columns = [];
        foreach ($keys as $key => $phone) {
            $columns[] = ['key' => $key, 'label' => $this->translator->trans('invoice.sends.column.'.$key), 'phone' => $phone];
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
        $cells = [
            'number' => $row->documentNumber,
            'when' => $when,
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
            'secondary' => implode(' · ', array_filter([$when, $recipients, $error], static fn (?string $part): bool => null !== $part && '' !== $part)),
            'cells' => $cells,
            'actions' => [
                ['label' => $this->translator->trans('invoice.sends.action.again'), 'href' => $this->generateUrl('app_invoice_send', $id), 'method' => 'GET', 'icon' => 'send', 'dialog' => true],
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
