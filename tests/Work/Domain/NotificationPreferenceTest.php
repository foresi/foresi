<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Domain;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationPreferenceTest extends TestCase
{
    private Membership $membership;

    protected function setUp(): void
    {
        $this->membership = new Membership(new User('greta@example.com', 'hash', 'Greta'), Role::Member);
    }

    public function testMentionsAndAssignmentsComeAtOnceTheRestInTheDigestAtEight(): void
    {
        $preference = new NotificationPreference($this->membership);

        self::assertTrue(Id::isValid($preference->id()));
        self::assertSame($this->membership, $preference->membership());
        self::assertSame([
            'mentioned' => 'immediate',
            'assigned' => 'immediate',
            'commented' => 'digest',
            'status_changed' => 'digest',
            'shared' => 'digest',
        ], $this->deliveries($preference));
        self::assertSame(8, $preference->digestHour());
        self::assertFalse($preference->wantsNoEmail());
        self::assertNull($preference->lastDigestAt());
    }

    public function testEachKindIsChosenOnItsOwn(): void
    {
        $preference = new NotificationPreference($this->membership);
        $preference->change(NotificationKind::Mentioned, EmailDelivery::Digest);
        $preference->change(NotificationKind::Assigned, EmailDelivery::Off);
        $preference->change(NotificationKind::Commented, EmailDelivery::Off);
        $preference->change(NotificationKind::StatusChanged, EmailDelivery::Off);
        $preference->change(NotificationKind::Shared, EmailDelivery::Off);
        $preference->change(NotificationKind::StatusChanged, EmailDelivery::Digest);

        self::assertSame(['mentioned' => 'digest', 'assigned' => 'off', 'commented' => 'off', 'status_changed' => 'digest', 'shared' => 'off'], $this->deliveries($preference));
    }

    /**
     * @return iterable<string, array{NotificationKind}>
     */
    public static function newsOfAWatchedTask(): iterable
    {
        yield 'commented' => [NotificationKind::Commented];
        yield 'status changed' => [NotificationKind::StatusChanged];
        yield 'shared' => [NotificationKind::Shared];
    }

    #[DataProvider('newsOfAWatchedTask')]
    public function testOnlyWhatIsAddressedToThePersonComesAtOnce(NotificationKind $kind): void
    {
        $preference = new NotificationPreference($this->membership);
        self::assertFalse(NotificationPreference::allows($kind, EmailDelivery::Immediate));
        self::assertTrue(NotificationPreference::allows($kind, EmailDelivery::Digest));
        self::assertTrue(NotificationPreference::allows($kind, EmailDelivery::Off));

        try {
            $preference->change($kind, EmailDelivery::Immediate);
            self::fail('A kind addressed to everyone watching came at once.');
        } catch (\DomainException $refused) {
            self::assertSame(sprintf('Only a mention or an assignment is mailed at once, not "%s".', $kind->value), $refused->getMessage());
        }
        self::assertSame(EmailDelivery::Digest, $preference->delivery($kind));
    }

    public function testTheDigestHourIsAnHourOfTheDay(): void
    {
        $preference = new NotificationPreference($this->membership);
        $preference->changeDigestHour(0);
        self::assertSame(0, $preference->digestHour());
        $preference->changeDigestHour(23);
        self::assertSame(23, $preference->digestHour());

        foreach ([-1, 24] as $hour) {
            try {
                $preference->changeDigestHour($hour);
                self::fail(sprintf('The hour %d was taken.', $hour));
            } catch (\DomainException $refused) {
                self::assertSame(sprintf('The digest comes at an hour from 0 to 23, not %d.', $hour), $refused->getMessage());
            }
        }
        self::assertSame(23, $preference->digestHour());
    }

    public function testNoEMailMailsNothingButKeepsTheChoices(): void
    {
        $preference = new NotificationPreference($this->membership);
        $preference->stopEmails(true);

        self::assertTrue($preference->wantsNoEmail());
        foreach (NotificationKind::cases() as $kind) {
            self::assertSame(EmailDelivery::Off, $preference->mails($kind));
        }
        self::assertSame(EmailDelivery::Immediate, $preference->delivery(NotificationKind::Mentioned));

        $preference->stopEmails(false);
        self::assertSame(EmailDelivery::Immediate, $preference->mails(NotificationKind::Mentioned));
        self::assertSame(EmailDelivery::Digest, $preference->mails(NotificationKind::Shared));
    }

    public function testStoppingTheDigestTurnsOffWhatCameInItOnly(): void
    {
        $preference = new NotificationPreference($this->membership);
        $preference->change(NotificationKind::Assigned, EmailDelivery::Digest);
        $preference->stopDigest();

        self::assertSame(['mentioned' => 'immediate', 'assigned' => 'off', 'commented' => 'off', 'status_changed' => 'off', 'shared' => 'off'], $this->deliveries($preference));
    }

    public function testTheLastDigestIsRemembered(): void
    {
        $preference = new NotificationPreference($this->membership);
        $at = new \DateTimeImmutable('2026-11-10 07:00 UTC');
        $preference->digestSent($at);

        self::assertSame($at, $preference->lastDigestAt());
    }

    public function testTheTenantIsStampedOnPersist(): void
    {
        $this->expectException(\LogicException::class);
        new NotificationPreference($this->membership)->tenantId();
    }

    /**
     * @return array<string, string>
     */
    private function deliveries(NotificationPreference $preference): array
    {
        $deliveries = [];
        foreach (NotificationKind::cases() as $kind) {
            $deliveries[$kind->value] = $preference->delivery($kind)->value;
        }

        return $deliveries;
    }
}
