<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;

/**
 * What a person sets on a recurring template, on creation and on every edit.
 * Without a series each occurrence takes the firm's default one on its day.
 */
final readonly class RecurringContent
{
    public function __construct(
        public ?Series $series,
        public Party $customer,
        public PaymentMethod $paymentMethod,
        public ?string $bankAccount,
        public string $constantSymbol,
        public string $orderNumber,
        public string $description,
        public string $textBefore,
        public string $textAfter,
        public DocumentLanguage $language,
        public Recurrence $recurrence,
        public TaxPointRule $taxPoint,
        public int $dueDays,
        public RecurringDelivery $delivery,
        public string $name = '',
        public bool $copyToMe = true,
        public bool $copyToOthers = true,
    ) {
    }
}
