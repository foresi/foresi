<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Foresi\Shared\Domain\Currency;

/**
 * The invoices a bank movement may pay or refund, read for a module that pairs
 * movements with invoices. Only what asks for money: a tax document on payment
 * is paid by the payment it taxes.
 */
interface PayableInvoices
{
    /**
     * The invoices that are not in the trash, have something left to pay and
     * carry the variable symbol, leading zeros aside on both sides. Every
     * currency.
     *
     * @param string $variableSymbol digits without leading zeros, not empty
     *
     * @return list<PayableInvoice> oldest due first
     */
    public function withSymbol(string $variableSymbol): array;

    /**
     * The invoices in the currency that are not in the trash and have something
     * left to pay, oldest due first: those the text finds in the number, the
     * customer's name or the variable symbol, or all of them for no text, and the
     * ones named by id whatever the text.
     *
     * @param list<string> $ids
     *
     * @return list<PayableInvoice>
     */
    public function open(Currency $currency, string $text, array $ids, int $limit): array;

    /**
     * The invoices that are not in the trash and have something to refund, in
     * every currency, oldest due first: a credited or overpaid invoice, a final
     * invoice its advances exceed, an advance no final invoice deducts. The
     * remaining amount of each is what is to refund, and its issue date the later
     * of its own and its corrective documents'. With a symbol, only those that
     * carry it or have a corrective document outside the trash that carries it,
     * leading zeros aside.
     *
     * @param string|null $variableSymbol digits without leading zeros, or null for all of them
     *
     * @return list<PayableInvoice>
     */
    public function refunds(?string $variableSymbol): array;

    /**
     * The invoices that an incoming payment not cancelled paid from one of the
     * bank movements.
     *
     * @param list<string> $bankMovementIds
     *
     * @return list<string> invoice ids
     */
    public function paidFrom(array $bankMovementIds): array;
}
