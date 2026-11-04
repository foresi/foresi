<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\FirmReminders;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirmRemindersTest extends TestCase
{
    public function testAMinimumAppliesOnlyInItsOwnCurrency(): void
    {
        $reminders = $this->reminders(Money::of('500.00', Currency::CZK));

        self::assertFalse($reminders->isWorthReminding(Money::of('499.99', Currency::CZK)));
        self::assertTrue($reminders->isWorthReminding(Money::of('500.00', Currency::CZK)));
        // 10 EUR is below 500 in number, but another currency is never compared with the minimum.
        self::assertTrue($reminders->isWorthReminding(Money::of('10.00', Currency::EUR)));
        self::assertTrue($reminders->isWorthReminding(Money::of('0.01', Currency::EUR)));
        self::assertFalse($reminders->isWorthReminding(Money::ofMinor(0, Currency::EUR)), 'Nothing owed in any currency is not reminded.');
        self::assertFalse($reminders->isWorthReminding(Money::of('-5.00', Currency::EUR)));
    }

    public function testWithoutAMinimumAnyOpenAmountIsWorthIt(): void
    {
        $reminders = $this->reminders(null);

        self::assertTrue($reminders->isWorthReminding(Money::of('0.01', Currency::CZK)));
        self::assertTrue($reminders->isWorthReminding(Money::of('0.01', Currency::EUR)));
        self::assertFalse($reminders->isWorthReminding(Money::ofMinor(0, Currency::CZK)));
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

        $this->reminders(Money::ofMinor(0, Currency::CZK));
    }

    public function testTheSettingsAreStampedWithTheirTenantOnPersist(): void
    {
        $this->expectException(\LogicException::class);

        $this->reminders(null)->tenantId();
    }

    private function reminders(?Money $minimum): FirmReminders
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '10000', 'CZ'), VatStatus::NotRegistered, '', []);
        $reminders = FirmReminders::standard($firm);
        $reminders->change(true, 7, 7, $minimum, new \DateTimeImmutable('2026-11-02'));

        return $reminders;
    }
}
