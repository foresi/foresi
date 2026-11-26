<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Domain;

use Foresi\Identity\Domain\User;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEmailIsTrimmedAndLowercased(): void
    {
        $user = new User('  Testing@Example.COM ', 'hash', 'Example Company');

        self::assertSame('testing@example.com', $user->email());
    }

    public function testExposesItsData(): void
    {
        $user = new User('testing@example.com', 'hash', '  Example Company ', 'cs');

        self::assertSame('hash', $user->passwordHash());
        self::assertSame('Example Company', $user->displayName());
        self::assertSame('cs', $user->locale());
    }

    public function testLocaleCanBeChanged(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Company', 'en');

        $user->changeLocale('cs');

        self::assertSame('cs', $user->locale());
    }

    public function testUnsupportedLocaleIsRefusedAndTheOldOneKept(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Company', 'en');

        try {
            $user->changeLocale('de');
            self::fail('An unsupported locale must throw.');
        } catch (\InvalidArgumentException) {
            self::assertSame('en', $user->locale());
        }
    }

    public function testTheAppearanceStartsStandardAndNormalAndChangesToAKnownSize(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example Company');
        self::assertSame([User::TEXT_STANDARD, User::DENSITY_NORMAL], [$user->textSize(), $user->density()]);

        $user->changeTextSize(User::TEXT_LARGE);
        $user->changeDensity(User::DENSITY_COMPACT);
        self::assertSame(['large', 'compact'], [$user->textSize(), $user->density()]);

        foreach ([static fn () => $user->changeTextSize('huge'), static fn () => $user->changeDensity('comfortable')] as $change) {
            try {
                $change();
                self::fail('An unknown size must throw.');
            } catch (\InvalidArgumentException) {
                self::assertSame(['large', 'compact'], [$user->textSize(), $user->density()]);
            }
        }
    }

    public function testLocaleDefaultsToEnglish(): void
    {
        self::assertSame('en', new User('testing@example.com', 'hash', 'Example Company')->locale());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidData(): iterable
    {
        yield 'invalid e-mail' => ['not-an-email', 'hash', 'Example Company', 'en'];
        yield 'empty e-mail' => ['', 'hash', 'Example Company', 'en'];
        yield 'empty password hash' => ['testing@example.com', '', 'Example Company', 'en'];
        yield 'blank display name' => ['testing@example.com', 'hash', '  ', 'en'];
        yield 'unsupported locale' => ['testing@example.com', 'hash', 'Example Company', 'de'];
    }

    #[DataProvider('invalidData')]
    public function testRejectsInvalidData(string $email, string $hash, string $name, string $locale): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User($email, $hash, $name, $locale);
    }

    public function testAUserHasItsIdFromTheStart(): void
    {
        self::assertTrue(Id::isValid(new User('testing@example.com', 'hash', 'Example Company')->id()));
    }
}
