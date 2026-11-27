<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Format;

use Foresi\Ui\Format\DurationText;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A duration in words. The numbers go to the translator as text: as integers, a message
 * format would group their thousands.
 */
final class DurationTextTest extends TestCase
{
    public function testHoursAndMinutesAreSaidApart(): void
    {
        self::assertSame('ui.duration.hours{"hours":"2"} ui.duration.minutes{"minutes":"5"}', $this->text(125));
        self::assertSame('ui.duration.hours{"hours":"1000"}', $this->text(60_000));
    }

    public function testNoTimeIsZeroMinutes(): void
    {
        self::assertSame('ui.duration.minutes{"minutes":"0"}', $this->text(0));
        self::assertSame('ui.duration.minutes{"minutes":"59"}', $this->text(59));
    }

    private function text(int $minutes): string
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => $id.json_encode($parameters, \JSON_THROW_ON_ERROR));

        return new DurationText($translator)->shown($minutes);
    }
}
