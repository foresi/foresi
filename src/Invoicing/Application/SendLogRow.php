<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\SendStatus;

/**
 * One send in the send log, with the names as they were sent and the country of
 * the issuer, whose time zone its times are shown in.
 */
final readonly class SendLogRow
{
    /**
     * @param list<string> $recipients   To, then Cc
     * @param int|null     $reminderStep for a reminder, which one of the invoice
     */
    public function __construct(
        public string $sendId,
        public string $invoiceId,
        public \DateTimeImmutable $createdAt,
        public string $firmName,
        public string $issuerCountry,
        public string $documentNumber,
        public string $customerName,
        public array $recipients,
        public SendStatus $status,
        public ?\DateTimeImmutable $readAt,
        public ?string $error,
        public ?string $errorCode = null,
        public ?int $errorSmtp = null,
        public SendPurpose $purpose = SendPurpose::Invoice,
        public ?int $reminderStep = null,
    ) {
    }
}
