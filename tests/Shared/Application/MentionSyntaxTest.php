<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Application;

use App\Shared\Application\MentionSyntax;
use PHPUnit\Framework\TestCase;

final class MentionSyntaxTest extends TestCase
{
    private const string JANA = '0190a8d2-1234-7abc-8def-0123456789ab';

    private const string PETR = '0190a8d2-5678-7abc-8def-0123456789ab';

    public function testListsEachMentionedMembershipOnceInOrder(): void
    {
        $source = sprintf("@[Petr](membership:%s), see this.\n@[Jana Nová](membership:%s) and @[Petr](membership:%s)", self::PETR, self::JANA, strtoupper(self::PETR));

        self::assertSame([self::PETR, self::JANA], MentionSyntax::ids($source));
    }

    public function testIgnoresWhatIsNoMention(): void
    {
        $source = sprintf('[Jana](membership:%s) @[Jana](member:%s) @[](membership:%s) @[Jana](membership:not-an-id) jana@example.com', self::JANA, self::JANA, self::JANA);

        self::assertSame([], MentionSyntax::ids($source));
    }
}
