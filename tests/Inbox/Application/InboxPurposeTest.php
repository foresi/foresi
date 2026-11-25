<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Inbox\Application\InboxPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What Mail is told of the inbox: mail for a person, whose address is shown once.
 */
final class InboxPurposeTest extends KernelTestCase
{
    public function testItTakesMailForAPersonAndItsAddressIsNotShownAgain(): void
    {
        $purpose = self::getContainer()->get(InboxPurpose::class);

        self::assertSame(['inbox', 'Inbox', true, false], [$purpose->key(), $purpose->label(), $purpose->ownerRequired(), $purpose->reshowable()]);
    }
}
