<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Application\UnknownPayment;
use App\Shared\Domain\Id;

/**
 * One payment with the invoices it pays, for the API and MCP. Only the owners
 * and members of the organisation read it, a cancelled payment too.
 */
final readonly class PaymentDetails
{
    public function __construct(private PaymentAccess $access, private PaymentQueries $queries)
    {
    }

    /**
     * @throws PaymentsNotAllowed for anyone but the owners and members of the organisation
     * @throws UnknownPayment     also for a payment of another tenant
     */
    public function of(string $id): PaymentRow
    {
        $this->access->denyUnlessStaff();

        return Id::isValid($id) ? ($this->queries->find($id) ?? throw new UnknownPayment()) : throw new UnknownPayment();
    }
}
