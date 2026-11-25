<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Foresi\Mail\Application\InboundPurposes;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use PHPUnit\Framework\TestCase;

/**
 * The purposes the installed modules register, found by their key.
 */
final class InboundPurposesTest extends TestCase
{
    public function testAPurposeIsFoundByItsKey(): void
    {
        $first = new RecordingPurpose('first', false);
        $second = new RecordingPurpose('second', true);
        $purposes = new InboundPurposes([$first, $second]);

        self::assertSame($second, $purposes->get('second'));
        self::assertSame($first, $purposes->get('first'));
        self::assertNull($purposes->get('removed_module'));
    }

    public function testTheFirstOfTwoPurposesWithOneKeyWins(): void
    {
        $first = new RecordingPurpose('shared', false);
        $purposes = new InboundPurposes([$first, new RecordingPurpose('shared', true)]);

        self::assertSame($first, $purposes->get('shared'));
    }
}
