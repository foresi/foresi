<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Application;

use Foresi\Purchasing\Application\PurchasingRefused;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Application\RefusalKind;
use PHPUnit\Framework\TestCase;

/**
 * What the received documents refuse over the API: a conflict, an outage or
 * an invalid field, with a message an agent can act on.
 */
final class PurchasingRefusedTest extends TestCase
{
    public function testAConflictKeepsItsOwnMessage(): void
    {
        $refused = PurchasingRefused::of(new ReceivedDocumentRefused(ReceivedDocumentRefused::PAID));

        self::assertSame(['paid', RefusalKind::Conflict, null, 'The received document is paid in full already.'], [$refused->reason(), $refused->kind(), $refused->field(), $refused->getMessage()]);
        self::assertSame([], $refused->details());
        self::assertInstanceOf(ReceivedDocumentRefused::class, $refused->getPrevious());
    }

    public function testAMissingRateIsAnOutage(): void
    {
        self::assertSame(RefusalKind::Unavailable, PurchasingRefused::of(new ReceivedDocumentRefused(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE))->kind());
    }

    public function testAnInvalidValueNamesItsField(): void
    {
        $refused = PurchasingRefused::of(new ReceivedDocumentRefused(ReceivedDocumentRefused::VARIABLE_SYMBOL));

        self::assertSame([RefusalKind::Invalid, 'variableSymbol', 'The received document refuses the change: variable_symbol.'], [$refused->kind(), $refused->field(), $refused->getMessage()]);
    }

    public function testAnUnknownFirmIsNotFound(): void
    {
        $refused = PurchasingRefused::unknownFirm();

        self::assertSame(['firm_unknown', RefusalKind::NotFound, 'firmId'], [$refused->reason(), $refused->kind(), $refused->field()]);
    }
}
