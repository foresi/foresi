<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Avatar as Initials;
use App\Ui\Component\Avatar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The avatar's own logic, without a kernel: its classes are compared whole.
 */
final class AvatarLogicTest extends TestCase
{
    private function avatar(string $name, string $size = 'sm', ?string $seed = null): Avatar
    {
        $avatar = new Avatar();
        $avatar->name = $name;
        $avatar->size = $size;
        $avatar->seed = $seed;

        return $avatar;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sizes(): iterable
    {
        yield 'sm' => ['sm', 'size-6 text-caption', 'size-3.5'];
        yield 'md' => ['md', 'size-8 text-caption', 'size-4'];
        yield 'lg' => ['lg', 'size-9 text-small', 'size-5'];
    }

    #[DataProvider('sizes')]
    public function testTheClassesAreTheCircleTheSizeAndTheTone(string $size, string $classes, string $icon): void
    {
        self::assertSame('inline-grid shrink-0 place-items-center rounded-avatar font-semibold leading-none select-none '.$classes.' '.Initials::tone('u-1'), $this->avatar('Jana Example', $size, 'u-1')->classes());
        self::assertSame($icon, $this->avatar('Jana Example', $size)->iconClasses());
    }

    public function testABlankNameIsNobody(): void
    {
        $avatar = $this->avatar('  ');

        self::assertTrue($avatar->nobody());
        self::assertSame('inline-grid shrink-0 place-items-center rounded-avatar font-semibold leading-none select-none size-6 text-caption border border-dashed border-container bg-white text-muted', $avatar->classes());
        self::assertFalse($this->avatar('Jana')->nobody());
    }

    public function testAnUnknownSizeNamesTheSizesThatExist(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Unknown avatar size "xl", expected one of: sm, md, lg.'));
        $this->avatar('Jana', 'xl')->classes();
    }
}
