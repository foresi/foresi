<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Payload;

use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Shared\Domain\Module\ModuleId;
use App\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued;
use App\Tests\Events\Fixtures\Valid\Contract\Kind;
use PHPUnit\Framework\TestCase;

final class EventPayloadsTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $payloads = new EventPayloads();
        $event = new InvoiceIssued('0192f0a0-0000-7000-8000-000000000001', 2, true, null, Kind::CreditNote, ['A1', 'B2']);
        $type = EventCatalogue::scan([[ModuleId::of('invoicing'), 'App\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']])->byName('invoicing.invoice.issued', 1);

        $data = $payloads->normalise($event);

        self::assertSame([
            'invoice_id' => '0192f0a0-0000-7000-8000-000000000001',
            'line_count' => 2,
            'delivered' => true,
            'due_on' => null,
            'kind' => 'credit_note',
            'line_codes' => ['A1', 'B2'],
        ], $data);
        self::assertEquals($event, $payloads->denormalise($type, $data));
    }
}
