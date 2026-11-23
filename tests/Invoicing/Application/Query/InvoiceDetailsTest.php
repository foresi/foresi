<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\Query\InvoiceDetails;
use Foresi\Invoicing\Application\UnknownInvoice;

final class InvoiceDetailsTest extends InvoicingReadTestCase
{
    public function testTheGuestIsRefusedBeforeAnythingIsLookedUp(): void
    {
        $this->signIn($this->guest);

        $this->expectException(InvoicingNotAllowed::class);
        $this->details()->of('01963c5e-0000-7000-8000-00000000000a');
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAnUnknownInvoiceAndAnIdThatIsNoneAreUnknown(): void
    {
        foreach (['01963c5e-0000-7000-8000-00000000000a', 'not-an-id', ''] as $id) {
            try {
                $this->details()->of($id);
                self::fail('The invoice was found.');
            } catch (UnknownInvoice) {
            }
        }
    }

    private function details(): InvoiceDetails
    {
        return self::getContainer()->get(InvoiceDetails::class);
    }
}
