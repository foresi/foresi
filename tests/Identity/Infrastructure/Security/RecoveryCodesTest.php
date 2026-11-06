<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\RecoveryCodes;
use PHPUnit\Framework\TestCase;

final class RecoveryCodesTest extends TestCase
{
    public function testGeneratesTenDistinctCodesInGroupsOfFour(): void
    {
        $codes = new RecoveryCodes()->generate();

        self::assertCount(RecoveryCodes::COUNT, $codes);
        self::assertCount(RecoveryCodes::COUNT, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){3}$/', $code);
        }
    }

    public function testTheHashIgnoresCaseSpacesAndDashes(): void
    {
        $codes = new RecoveryCodes();

        self::assertSame(hash('sha256', 'ABCDEFGHJKLMNPQR'), $codes->hash('ABCD-EFGH-JKLM-NPQR'));
        self::assertSame($codes->hash('ABCD-EFGH-JKLM-NPQR'), $codes->hash(" abcd efgh\tjklm--npqr "));
        self::assertNotSame($codes->hash('ABCD-EFGH-JKLM-NPQR'), $codes->hash('ABCD-EFGH-JKLM-NPQS'));
    }
}
