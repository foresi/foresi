<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Support;

use Doctrine\DBAL\Connection;
use Foresi\Invoicing\Application\InvoiceBalances;
use Foresi\Invoicing\Infrastructure\Doctrine\OwingInvoiceCondition;
use Foresi\Invoicing\Infrastructure\Doctrine\PaymentBalances;
use Foresi\Shared\Domain\Money;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pays invoices without recording a payment: the invoices it is told of are paid
 * in full, every other one owes what PaymentBalances says. Its SQL condition is
 * PaymentBalances' own and lets these invoices through, so whatever reads it must
 * still drop them by outstanding().
 */
final class SettledBalances implements InvoiceBalances, OwingInvoiceCondition
{
    /** @var array<string, true> */
    private array $paid = [];

    public function __construct(private readonly PaymentBalances $inner)
    {
    }

    /**
     * Replaces the bound balances of the test container; call it before anything uses them.
     */
    public static function install(ContainerInterface $container, Connection $connection): self
    {
        $balances = new self(new PaymentBalances($connection));
        $container->set(PaymentBalances::class, $balances);

        return $balances;
    }

    public function pay(string $invoiceId): void
    {
        $this->paid[$invoiceId] = true;
    }

    public function outstanding(array $invoiceIds): array
    {
        $owed = $this->inner->outstanding($invoiceIds);
        foreach ($owed as $id => $amount) {
            if (isset($this->paid[$id])) {
                $owed[$id] = Money::ofMinor(0, $amount->currency());
            }
        }

        return $owed;
    }

    public function owing(string $invoice): string
    {
        return $this->inner->owing($invoice);
    }

    public function isAuthoritative(): bool
    {
        return true;
    }
}
