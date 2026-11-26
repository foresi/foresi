<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Domain;

use Foresi\Identity\Domain\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    public function testRenameTrimsTheName(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Person');

        $user->rename('  Jane Example ');

        self::assertSame('Jane Example', $user->displayName());
    }

    #[DataProvider('badNames')]
    public function testRenameRefusesAnUnusableName(string $name): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Person');

        try {
            $user->rename($name);
            self::fail('The name was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('Example Person', $user->displayName());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('a', User::MAX_DISPLAY_NAME_LENGTH + 1)];
    }

    public function testTheLongestNameIsAccepted(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Person');

        $user->rename(str_repeat('a', User::MAX_DISPLAY_NAME_LENGTH));

        self::assertSame(User::MAX_DISPLAY_NAME_LENGTH, mb_strlen($user->displayName()));
    }

    public function testAPhotoGetsANewVersionEachTimeAndRemovingClearsIt(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Person');
        self::assertNull($user->photoVersion());

        $user->changePhoto();
        $first = $user->photoVersion();
        $user->changePhoto();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $first);
        self::assertNotSame($first, $user->photoVersion());

        $user->removePhoto();
        self::assertNull($user->photoVersion());
    }
}
