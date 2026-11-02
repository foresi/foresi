<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\TaxPointRule;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;

/**
 * What a save of a recurring template asks for, on creation and on an edit alike:
 * the editable fields of an invoice without its number and dates, and the schedule.
 * Without a series each occurrence takes the firm's default one. The texts may hold
 * period variables, kept as typed.
 */
final readonly class RecurringInput
{
    /** @var list<LineInput> */
    public array $lines;

    /**
     * @param array<LineInput> $lines keys are discarded
     */
    public function __construct(
        public string $firmId,
        public ?string $seriesId,
        public string $customerId,
        public PaymentMethod $paymentMethod,
        public ?string $bankAccount,
        public string $constantSymbol,
        public string $orderNumber,
        public string $description,
        public string $textBefore,
        public string $textAfter,
        public DocumentLanguage $language,
        array $lines,
        public Recurrence $recurrence,
        public TaxPointRule $taxPoint,
        public int $dueDays,
        public RecurringDelivery $delivery,
    ) {
        $this->lines = array_values($lines);
    }
}
