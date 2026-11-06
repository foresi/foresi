<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What became of one imported payment.
 */
final readonly class ImportedPaymentResult
{
    /**
     * @param string|null $reason why it was refused, as PaymentRefused names it
     */
    private function __construct(public ImportOutcome $outcome, public ?string $paymentId = null, public ?string $reason = null)
    {
    }

    public static function imported(string $paymentId): self
    {
        return new self(ImportOutcome::Imported, $paymentId);
    }

    public static function alreadyImported(string $paymentId): self
    {
        return new self(ImportOutcome::AlreadyImported, $paymentId);
    }

    public static function refused(string $reason): self
    {
        return new self(ImportOutcome::Refused, reason: $reason);
    }
}
