<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Domain;

use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\LabelColour;
use PHPUnit\Framework\TestCase;

/**
 * A label goes to the trash with who did it and when, gives up its name there
 * and takes it back on restore.
 */
final class LabelTest extends TestCase
{
    public function testTrashingKeepsTheFirstDateAndWho(): void
    {
        $label = new Label('Urgent', LabelColour::Red);
        self::assertFalse($label->isDeleted());

        $label->trash('user-1', new \DateTimeImmutable('2026-11-19 10:00'));
        $label->trash('user-2', new \DateTimeImmutable('2026-11-20 10:00'));

        self::assertTrue($label->isDeleted());
        self::assertSame(['2026-11-19 10:00', 'user-1'], [$label->deletedAt()?->format('Y-m-d H:i'), $label->deletedBy()]);
    }

    public function testTheNameIsHeldOnlyOutOfTheTrash(): void
    {
        $label = new Label(' Urgent ', LabelColour::Red);
        self::assertSame('Urgent', $this->liveName($label));

        $label->trash('user-1', new \DateTimeImmutable('2026-11-19 10:00'));
        self::assertNull($this->liveName($label));
        $label->rename('Later');
        self::assertNull($this->liveName($label));

        $label->restore();
        self::assertSame(['Later', 'Later', null, null], [$label->name(), $this->liveName($label), $label->deletedAt(), $label->deletedBy()]);
        self::assertFalse($label->isDeleted());
        $label->rename('Soon');
        self::assertSame('Soon', $this->liveName($label));
    }

    private function liveName(Label $label): ?string
    {
        $value = new \ReflectionProperty(Label::class, 'liveName')->getValue($label);
        self::assertTrue(null === $value || \is_string($value));

        return $value;
    }
}
