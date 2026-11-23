<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Api;

use Foresi\Invoicing\Application\Api\CreateRecurringInvoiceInput;
use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Tests\Invoicing\Application\Query\InvoicingReadTestCase;

/**
 * The recurring writes of the API and MCP are for the staff of the organisation:
 * a guest reads, lists, previews and saves no template, whatever the routes let through.
 */
final class RecurringWritesTest extends InvoicingReadTestCase
{
    public function testAGuestIsRefusedEveryUseOfTheTemplates(): void
    {
        $writes = self::getContainer()->get(RecurringWrites::class);
        $input = new CreateRecurringInvoiceInput('review', customerId: $this->customer->id(), lines: [['text' => 'Hosting', 'unit_price' => '1000.00']], firstIssueOn: '2026-12-01');
        self::assertSame(0, $writes->page(null, 1)->total, 'the owner lists them');

        $this->signIn($this->guest);

        $uses = [
            'list' => static fn (): mixed => $writes->page(null, 1),
            'read' => static fn (): mixed => $writes->template('01940000-0000-7000-8000-000000000001'),
            'preview' => static fn (): mixed => $writes->preview($input, 3),
            'create' => static fn (): mixed => $writes->create($input),
        ];
        foreach ($uses as $use => $call) {
            try {
                $call();
                self::fail(\sprintf('A guest may not %s a template.', $use));
            } catch (InvoicingRefused $refused) {
                self::assertSame(RefusalKind::Forbidden, $refused->kind(), $use);
            }
        }
    }
}
