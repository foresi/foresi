<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application;

use Foresi\Party\Application\ContactPartRefused;
use PHPUnit\Framework\TestCase;

/**
 * A bank account as people write it, read as an IBAN, and the refusal that
 * names every wrong field of a contact part.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class AccountNumbersTest extends TestCase
{
    public function testTheRefusalNamesEveryFieldWithItsReason(): void
    {
        $refused = new ContactPartRefused(['name' => ContactPartRefused::REQUIRED, 'email' => ContactPartRefused::EMAIL]);

        self::assertSame('The contact part is refused: name required, email email.', $refused->getMessage());
        self::assertSame(['name' => 'required', 'email' => 'email'], $refused->reasons);
    }
}
