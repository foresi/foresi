<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Why a document of an import cannot be taken back: something recorded here after
 * the import stands on it, or the time to undo is over. A payment of the import
 * names itself by its id, with neither a document nor a number.
 */
final readonly class UndoBlocker
{
    public const string EXPIRED = 'expired';
    public const string CHANGED = 'changed';
    public const string EXPORTED = 'exported';
    public const string PAYMENTS = 'payments';
    public const string SENDS = 'sends';
    public const string CORRECTIONS = 'corrections';
    public const string TAX_DOCUMENTS = 'tax_documents';
    public const string DEDUCTIONS = 'deductions';
    public const string CANCELLED = 'cancelled';

    public function __construct(public string $invoiceId, public string $number, public string $reason, public ?string $paymentId = null)
    {
    }

    public static function ofPayment(string $paymentId, string $reason): self
    {
        return new self('', '', $reason, $paymentId);
    }
}
