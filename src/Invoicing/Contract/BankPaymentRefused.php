<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

/**
 * A bank payment was not recorded, and nothing of it was stored. The reason is a
 * stable name, one of the reasons a payment is refused or UNKNOWN_INVOICE; the
 * problem is the translation key the payment screens show for it, with its
 * parameters, or null for an unknown invoice, which the caller names itself.
 */
final class BankPaymentRefused extends \DomainException
{
    public const string UNKNOWN_INVOICE = 'unknown_invoice';
    /** Invoices with its symbol exist, all in another currency. */
    public const string CURRENCY_MISMATCH = 'currency_mismatch';
    /** The invoice is in the trash. */
    public const string TRASHED = 'trashed';

    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(public readonly string $reason, public readonly ?string $problem, public readonly array $parameters = [])
    {
        parent::__construct(\sprintf('The bank payment was refused: %s.', $reason));
    }
}
