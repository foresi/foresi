<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\Invoicing;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The issued invoices in the cash-flow forecast over REST: a token without the
 * scope of the documents sees their totals, not the documents.
 */
final class CashFlowInvoicesApiTest extends ApiTestCase
{
    use IssuesInvoices;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-17 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testATokenWithoutTheDocumentScopesSeesTheirTotalsButNotTheItems(): void
    {
        $invoice = $this->flushed(fn (): string => $this->invoice()->id());
        $restricted = ['invoicing.invoices', 'invoicing.recurring_invoices', 'purchasing.received_documents'];

        $this->get('/api/v1/cash-flow/forecast', [], $this->issued([ApiScope::from('cashflow:read')]));

        self::assertResponseIsSuccessful();
        self::assertSame($restricted, $this->at('data', 'restricted_sources'));
        self::assertSame([], $this->at('data', 'periods', 0, 'items'));
        self::assertSame([[
            'source' => 'invoicing.invoices',
            'direction' => 'in',
            'category' => 'revenue',
            'amount_czk' => ['amount_minor' => 242000, 'currency' => 'CZK'],
            'count' => 1,
        ]], $this->at('data', 'periods', 0, 'hidden_items'));
        self::assertSame(['amount_minor' => 242000, 'currency' => 'CZK'], $this->at('data', 'periods', 0, 'inflows', 'total'));

        $this->get('/api/v1/cash-flow/forecast', [], $this->issued([ApiScope::from('cashflow:read'), ApiScope::from('invoices:read')]));

        self::assertSame(['purchasing.received_documents'], $this->at('data', 'restricted_sources'));
        self::assertSame([], $this->at('data', 'periods', 0, 'hidden_items'));
        self::assertSame([$invoice, ['overdue']], [$this->at('data', 'periods', 0, 'items', 0, 'subject', 'id'), $this->at('data', 'periods', 0, 'items', 0, 'flags')]);
    }
}
