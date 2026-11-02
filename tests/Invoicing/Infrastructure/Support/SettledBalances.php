<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Support;

use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Infrastructure\Doctrine\DueDateBalances;
use App\Invoicing\Infrastructure\Doctrine\OwingInvoiceCondition;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stands in for the payments module: the invoices it is told of are paid in
 * full, every other one owes what DueDateBalances says. Its SQL condition is
 * DueDateBalances' own and lets paid invoices through, so whatever reads it must
 * still drop them by outstanding().
 */
final class SettledBalances implements InvoiceBalances, OwingInvoiceCondition
{
    /** @var array<string, true> */
    private array $paid = [];

    public function __construct(private readonly DueDateBalances $inner)
    {
    }

    /**
     * Replaces the bound balances of the test container; call it before anything uses them.
     */
    public static function install(ContainerInterface $container, Connection $connection, TenantContext $context): self
    {
        $balances = new self(new DueDateBalances($connection, $context));
        $container->set(DueDateBalances::class, $balances);

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
