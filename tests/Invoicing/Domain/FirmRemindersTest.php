<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Domain;

use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirmRemindersTest extends TestCase
{
    public function testAMinimumAppliesOnlyInItsOwnCurrency(): void
    {
        $reminders = $this->reminders(Money::of('500.00', Currency::of('CZK')));

        self::assertFalse($reminders->isWorthReminding(Money::of('499.99', Currency::of('CZK'))));
        self::assertTrue($reminders->isWorthReminding(Money::of('500.00', Currency::of('CZK'))));
        // 10 EUR is below 500 in number, but another currency is never compared with the minimum.
        self::assertTrue($reminders->isWorthReminding(Money::of('10.00', Currency::of('EUR'))));
        self::assertTrue($reminders->isWorthReminding(Money::of('0.01', Currency::of('EUR'))));
        self::assertFalse($reminders->isWorthReminding(Money::ofMinor(0, Currency::of('EUR'))), 'Nothing owed in any currency is not reminded.');
        self::assertFalse($reminders->isWorthReminding(Money::of('-5.00', Currency::of('EUR'))));
    }

    public function testWithoutAMinimumAnyOpenAmountIsWorthIt(): void
    {
        $reminders = $this->reminders(null);

        self::assertTrue($reminders->isWorthReminding(Money::of('0.01', Currency::of('CZK'))));
        self::assertTrue($reminders->isWorthReminding(Money::of('0.01', Currency::of('EUR'))));
        self::assertFalse($reminders->isWorthReminding(Money::ofMinor(0, Currency::of('CZK'))));
    }

    public function testTheDelaysRunFromOneTo365Days(): void
    {
        $reminders = $this->reminders(null);

        $reminders->change(true, FirmReminders::MIN_DAYS, FirmReminders::MAX_DAYS, null, new \DateTimeImmutable('2026-11-02'));

        self::assertSame([FirmReminders::MIN_DAYS, FirmReminders::MAX_DAYS], [$reminders->firstAfterDays(), $reminders->everyDays()]);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function delaysOutOfRange(): iterable
    {
        yield 'first after no days' => [0, 7];
        yield 'first after over a year' => [366, 7];
        yield 'every no days' => [7, 0];
        yield 'every over a year' => [7, 366];
    }

    #[DataProvider('delaysOutOfRange')]
    public function testADelayOutOfRangeIsRefused(int $firstAfterDays, int $everyDays): void
    {
        $this->expectException(\DomainException::class);

        $this->reminders(null)->change(true, $firstAfterDays, $everyDays, null, new \DateTimeImmutable('2026-11-02'));
    }

    public function testAMinimumOfZeroIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        $this->reminders(Money::ofMinor(0, Currency::of('CZK')));
    }

    private function reminders(?Money $minimum): FirmReminders
    {
        $reminders = FirmReminders::standard();
        $reminders->change(true, 7, 7, $minimum, new \DateTimeImmutable('2026-11-02'));

        return $reminders;
    }
}
