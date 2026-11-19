<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\SendLogRow;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Application\View\SendLogLists;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Infrastructure\Form\SendFailureText;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the send log, the ones it showed before saved views; each cell renders
 * one SendLogRow. The names, addresses and errors give way, cut short with their full
 * text in a title, so the table fits its frame. Times are in the time zone of the
 * country that issued the invoice.
 */
final readonly class SendLogColumns implements DataSource
{
    public const string LIST = SendLogLists::KEY;
    private const array TONES = ['queued' => 'gray', 'sent' => 'green', 'failed' => 'red', 'cancelled' => 'gray'];

    public function __construct(
        private TranslatorInterface $translator,
        private InvoicingCountries $countries,
        private SendFailureText $failures,
    ) {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'invoice.sends.column.'.$key;

        return [
            new Attribute('number', $label('number'), AttributeType::Text, static fn (SendLogRow $row): string => $row->documentNumber, defaultVisible: true, defaultPosition: 10, cell: static fn (SendLogRow $row): string => $row->documentNumber, locked: true),
            new Attribute('when', $label('when'), AttributeType::DateTime, fn (SendLogRow $row): \DateTimeImmutable => $row->createdAt->setTimezone($this->timezone($row->issuerCountry)), defaultVisible: true, defaultPosition: 20, cell: $this->when(...), phone: false),
            new Attribute('purpose', 'reminder.log.column', AttributeType::Text, $this->purpose(...), defaultVisible: true, defaultPosition: 30, cell: fn (SendLogRow $row): array => ['text' => $this->purpose($row), 'icon' => SendPurpose::Reminder === $row->purpose ? 'bell-ring' : 'receipt-text', 'tone' => 'gray'], phone: false),
            new Attribute('customer', $label('customer'), AttributeType::Text, static fn (SendLogRow $row): string => $row->customerName, defaultVisible: true, defaultPosition: 40, cell: static fn (SendLogRow $row): string => $row->customerName, phone: false, truncate: true),
            new Attribute('recipients', $label('recipients'), AttributeType::Text, $this->recipients(...), defaultVisible: true, defaultPosition: 50, cell: $this->recipients(...), phone: false, truncate: true),
            new Attribute('status', $label('status'), AttributeType::Text, static fn (SendLogRow $row): string => $row->status->value, defaultVisible: true, defaultPosition: 60, cell: $this->status(...)),
            new Attribute('error', $label('error'), AttributeType::Text, $this->error(...), defaultVisible: true, defaultPosition: 70, cell: fn (SendLogRow $row): string => $this->error($row) ?? '', phone: false, truncate: true),
        ];
    }

    public function when(SendLogRow $row): string
    {
        return $row->createdAt->setTimezone($this->timezone($row->issuerCountry))->format($this->translator->trans('format.datetime'));
    }

    /**
     * Which reminder of the invoice, or the invoice itself.
     */
    public function purpose(SendLogRow $row): string
    {
        return SendPurpose::Reminder === $row->purpose
            ? $this->translator->trans('reminder.log.step', ['step' => $row->reminderStep ?? 0])
            : $this->translator->trans('reminder.log.purpose.invoice');
    }

    public function recipients(SendLogRow $row): string
    {
        return implode(', ', $row->recipients);
    }

    public function error(SendLogRow $row): ?string
    {
        return $this->failures->of($row->errorCode, $row->errorSmtp, $row->error);
    }

    /**
     * An opened send says so in its status, with the moment it was first opened.
     *
     * @return array{text: string, tone: string}
     */
    private function status(SendLogRow $row): array
    {
        if (!$row->readAt instanceof \DateTimeImmutable) {
            return ['text' => $this->translator->trans('invoice.sends.status.'.$row->status->value), 'tone' => self::TONES[$row->status->value]];
        }
        $read = $row->readAt->setTimezone($this->timezone($row->issuerCountry))->format($this->translator->trans('format.datetime'));

        return ['text' => $this->translator->trans('invoice.history.send_read', ['date' => $read]), 'tone' => 'green'];
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
