<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Domain;

use App\Webhooks\Domain\DisabledReason;
use App\Webhooks\Domain\EndpointUrl;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WebhookEndpointTest extends TestCase
{
    private const string ID = '019a0000-0000-7000-8000-000000000001';
    private const string OWNER = '019a0000-0000-7000-8000-000000000002';

    public function testCreateKeepsWhatWasGiven(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($now);

        self::assertSame(self::ID, $endpoint->id());
        self::assertSame('https://hooks.example.com/x', $endpoint->url()->value);
        self::assertSame('Accounting sync', $endpoint->description());
        self::assertSame(['invoicing.*'], $endpoint->eventTypes()->patterns());
        self::assertSame(1, $endpoint->version());
        self::assertSame(self::OWNER, $endpoint->ownerId());
        self::assertTrue($endpoint->isActive());
        self::assertNull($endpoint->disabledReason());
        self::assertNull($endpoint->failingSince());
        self::assertSame(['sealed-1'], $endpoint->signingSecrets($now));
        self::assertEquals($now, $endpoint->createdAt());
        self::assertEquals($now, $endpoint->updatedAt());
    }

    #[DataProvider('versions')]
    public function testTheVersionIsPositive(int $version): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WebhookEndpoint::create(self::ID, new EndpointUrl('https://hooks.example.com/x'), null, EventTypes::of(['invoicing.*']), $version, 'sealed-1', self::OWNER, new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function versions(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'beyond a small integer' => [32768];
    }

    public function testTheLongestDescriptionAndHighestVersionAreAccepted(): void
    {
        $description = str_repeat('č', WebhookEndpoint::DESCRIPTION_MAX_LENGTH);
        $endpoint = WebhookEndpoint::create(self::ID, new EndpointUrl('https://hooks.example.com/x'), $description, EventTypes::of(['invoicing.*']), WebhookEndpoint::MAX_VERSION, 'sealed-1', self::OWNER, new \DateTimeImmutable());

        self::assertSame($description, $endpoint->description());
        self::assertSame(WebhookEndpoint::MAX_VERSION, $endpoint->version());
    }

    public function testTheTenantIsKnownOnlyOncePersisted(): void
    {
        $this->expectException(\LogicException::class);

        $this->endpoint(new \DateTimeImmutable())->tenantId();
    }

    public function testChangeReplacesOnlyWhatIsGiven(): void
    {
        $endpoint = $this->endpoint(new \DateTimeImmutable('2026-11-13 10:00:00'));
        $later = new \DateTimeImmutable('2026-11-13 11:00:00');

        $endpoint->change($later, version: 2);
        self::assertSame(2, $endpoint->version());
        self::assertSame('Accounting sync', $endpoint->description());
        self::assertSame('https://hooks.example.com/x', $endpoint->url()->value);
        self::assertEquals($later, $endpoint->updatedAt());

        $endpoint->change($later, new EndpointUrl('https://other.example.com/y'), '  ', EventTypes::of(['invoicing.invoice.issued']));
        self::assertSame('https://other.example.com/y', $endpoint->url()->value);
        self::assertNull($endpoint->description(), 'A blank description clears it.');
        self::assertSame(['invoicing.invoice.issued'], $endpoint->eventTypes()->patterns());
    }

    public function testSigningSecretsIncludePreviousUntilItExpires(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($now);

        $endpoint->rotate('sealed-2', $now->modify('+24 hours'), $now);

        self::assertSame(['sealed-2', 'sealed-1'], $endpoint->signingSecrets($now));
        self::assertSame(['sealed-2', 'sealed-1'], $endpoint->signingSecrets($now->modify('+23 hours 59 minutes')));
        self::assertSame(['sealed-2'], $endpoint->signingSecrets($now->modify('+24 hours')));
    }

    public function testARotationWithoutOverlapDropsThePreviousSecret(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($now);
        $endpoint->rotate('sealed-2', $now->modify('+1 hour'), $now);

        $endpoint->rotate('sealed-3', $now, $now);

        self::assertSame(['sealed-3'], $endpoint->signingSecrets($now));
        self::assertNull($endpoint->previousExpiresAt());
    }

    public function testFailedReturnsTrueAfterFiveDaysOfFailures(): void
    {
        $clock = new MockClock('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($clock->now());

        self::assertFalse($endpoint->failed($clock->now()));
        self::assertEquals($clock->now(), $endpoint->failingSince());

        $clock->modify('+4 days 23 hours');
        self::assertFalse($endpoint->failed($clock->now()));

        $clock->modify('+1 hour');
        self::assertTrue($endpoint->failed($clock->now()));
        self::assertEquals(new \DateTimeImmutable('2026-11-13 10:00:00'), $endpoint->failingSince(), 'Later failures keep the first one.');
    }

    public function testSuccessClearsFailingSince(): void
    {
        $clock = new MockClock('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($clock->now());
        $endpoint->failed($clock->now());

        $endpoint->succeeded();
        self::assertNull($endpoint->failingSince());

        $clock->modify('+5 days');
        self::assertFalse($endpoint->failed($clock->now()), 'A new run of failures starts from zero.');
    }

    public function testDisableKeepsTheReason(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($now);

        $endpoint->disable(DisabledReason::Gone, $now->modify('+1 hour'));

        self::assertFalse($endpoint->isActive());
        self::assertSame(DisabledReason::Gone, $endpoint->disabledReason());
        self::assertEquals($now->modify('+1 hour'), $endpoint->updatedAt());
    }

    public function testEnableClearsReason(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $endpoint = $this->endpoint($now);
        $endpoint->failed($now);
        $endpoint->disable(DisabledReason::Failing, $now);

        $endpoint->enable($now->modify('+1 day'));

        self::assertTrue($endpoint->isActive());
        self::assertNull($endpoint->disabledReason());
        self::assertNull($endpoint->failingSince());
        self::assertEquals($now->modify('+1 day'), $endpoint->updatedAt());
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('patterns')]
    public function testEventTypesRefuseWhatIsNotANameOrAModule(array $patterns): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EventTypes::of($patterns);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function patterns(): iterable
    {
        yield 'nothing' => [[]];
        yield 'everything' => [['*']];
        yield 'a wildcard inside a name' => [['invoicing.invoice.*']];
        yield 'a module alone' => [['invoicing']];
        yield 'upper case' => [['Invoicing.*']];
    }

    public function testEventTypesKeepEachPatternOnce(): void
    {
        self::assertSame(['invoicing.*', 'exchange-rate.rate.published'], EventTypes::of(['invoicing.*', 'exchange-rate.rate.published', 'invoicing.*'])->patterns());
        self::assertSame(['invoicing.*', 'exchange-rate.rate.published'], EventTypes::of(['invoicing.*', 'invoicing.*', 'exchange-rate.rate.published'])->patterns(), 'The patterns stay a list.');
    }

    public function testAnEndpointUrlNamesItsHost(): void
    {
        self::assertSame('hooks.example.com', new EndpointUrl('https://hooks.example.com:8443/in?token=x')->host());
    }

    #[DataProvider('urls')]
    public function testAnEndpointUrlIsHttpsWithAHostAndNoUserInfo(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EndpointUrl($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urls(): iterable
    {
        yield 'http' => ['http://hooks.example.com/x'];
        yield 'no host' => ['https:///x'];
        yield 'user info' => ['https://user:pw@hooks.example.com/x'];
        yield 'a user without a password' => ['https://user@hooks.example.com/x'];
        yield 'too long' => ['https://hooks.example.com/'.str_repeat('a', 2048)];
    }

    private function endpoint(\DateTimeImmutable $now): WebhookEndpoint
    {
        return WebhookEndpoint::create(self::ID, new EndpointUrl('https://hooks.example.com/x'), 'Accounting sync', EventTypes::of(['invoicing.*']), 1, 'sealed-1', self::OWNER, $now);
    }
}
