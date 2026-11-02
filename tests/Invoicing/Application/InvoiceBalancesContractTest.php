<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\UnknownInvoice;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;

/**
 * What every InvoiceBalances must do. An implementation extends this and says how
 * it is built and how an invoice becomes settled in its world.
 */
abstract class InvoiceBalancesContractTest extends InvoiceTestCase
{
    abstract protected function balances(): InvoiceBalances;

    /**
     * Makes the invoice settled the way the implementation understands it.
     */
    abstract protected function settle(string $invoiceId): void;

    /**
     * Moves the invoice to the trash. Every implementation must then answer zero
     * for it, whatever it knows about payments.
     */
    protected function trashInvoice(string $invoiceId): void
    {
        $this->trash->trash($invoiceId, 'Wrong customer');
    }

    public function testEveryIdIsAnswered(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input([$this->line('250.00')]));
        $this->em->clear();

        $outstanding = $this->balances()->outstanding([$first->id(), $second->id()]);

        self::assertEqualsCanonicalizing([$first->id(), $second->id()], array_keys($outstanding));
        self::assertSame(121000, $outstanding[$first->id()]->minor());
        self::assertSame(30300, $outstanding[$second->id()]->minor());
        self::assertSame(Currency::CZK, $outstanding[$first->id()]->currency());
    }

    public function testNoIdsNoAnswers(): void
    {
        self::assertSame([], $this->balances()->outstanding([]));
    }

    public function testASettledInvoiceIsZero(): void
    {
        $open = $this->issue->issue($this->input());
        $settled = $this->issue->issue($this->input());
        $this->settle($settled->id());
        $this->em->clear();

        $outstanding = $this->balances()->outstanding([$open->id(), $settled->id()]);

        self::assertSame(121000, $outstanding[$open->id()]->minor());
        self::assertArrayHasKey($settled->id(), $outstanding);
        self::assertSame(0, $outstanding[$settled->id()]->minor());
        self::assertSame(Currency::CZK, $outstanding[$settled->id()]->currency());
    }

    public function testATrashedInvoiceIsZero(): void
    {
        $open = $this->issue->issue($this->input());
        $trashed = $this->issue->issue($this->input([$this->line('250.00')]));
        $this->trashInvoice($trashed->id());
        $this->em->clear();

        $outstanding = $this->balances()->outstanding([$open->id(), $trashed->id()]);

        self::assertSame(121000, $outstanding[$open->id()]->minor());
        self::assertArrayHasKey($trashed->id(), $outstanding);
        self::assertSame(0, $outstanding[$trashed->id()]->minor());
        self::assertSame(Currency::CZK, $outstanding[$trashed->id()]->currency());
    }

    public function testOneQueryForManyIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; ++$i) {
            $ids[] = $this->issue->issue($this->input())->id();
        }
        $this->em->clear();
        $balances = $this->balances();

        $before = $this->queryCount();
        $outstanding = $balances->outstanding($ids);
        $used = $this->queryCount() - $before;

        self::assertCount(12, $outstanding);
        self::assertSame(1, $used, 'Queries used: '.$used);
    }

    public function testAnotherTenantsInvoiceIsUnknown(): void
    {
        $mine = $this->issue->issue($this->input());
        $this->em->clear();
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->expectException(UnknownInvoice::class);
        $this->balances()->outstanding([$mine->id()]);
    }

    public function testAnIdThatDoesNotExistIsUnknown(): void
    {
        $mine = $this->issue->issue($this->input());

        $this->expectException(UnknownInvoice::class);
        $this->balances()->outstanding([$mine->id(), '01963c5e-0000-7000-8000-0000000000ff']);
    }

    private function queryCount(): int
    {
        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }
}
