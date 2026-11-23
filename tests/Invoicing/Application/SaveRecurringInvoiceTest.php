<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\LineInput;

final class SaveRecurringInvoiceTest extends RecurringTestCase
{
    public function testAnInputCopiesToBothByDefaultAndListsItsLines(): void
    {
        $input = $this->recurringInput([3 => $this->line(text: 'First'), 7 => $this->line(text: 'Second')]);

        self::assertTrue($input->copyToMe);
        self::assertTrue($input->copyToOthers);
        self::assertSame(['First', 'Second'], array_map(static fn (LineInput $line): string => $line->text, $input->lines));
        self::assertSame([0, 1], array_keys($input->lines));
    }
}
