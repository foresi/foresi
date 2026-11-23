<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Domain;

use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationTest extends TestCase
{
    private const string CLIENT_A = '0197a1de-0000-7000-8000-00000000000a';
    private const string CLIENT_B = '0197a1de-0000-7000-8000-00000000000b';

    public function testAWholeAllocationHasOneShare(): void
    {
        $allocation = new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client(self::CLIENT_A))]);

        self::assertSame(AllocationKind::Whole, $allocation->kind);
        self::assertCount(1, $allocation->shares);
    }

    public function testWholeNeedsOne(): void
    {
        $this->assertRefused('whole_needs_one', fn (): Allocation => new Allocation(AllocationKind::Whole, []));
        $this->assertRefused('whole_needs_one', fn (): Allocation => new Allocation(AllocationKind::Whole, [
            new AllocationShare(Recipient::client(self::CLIENT_A)),
            new AllocationShare(Recipient::own()),
        ]));
        $this->assertRefused('whole_needs_one', fn (): Allocation => new Allocation(AllocationKind::Whole, [
            new AllocationShare(Recipient::own(), '100'),
        ]));
    }

    public function testPercentMustAddUpToExactlyOneHundred(): void
    {
        $percent = static function (string ...$values): Allocation {
            $shares = [];
            foreach (array_values($values) as $index => $value) {
                $shares[] = new AllocationShare(0 === $index ? Recipient::own() : Recipient::client(\sprintf('0197a1de-0000-7000-8000-%012d', $index)), $value);
            }

            return new Allocation(AllocationKind::Percent, $shares);
        };

        self::assertCount(3, $percent('30', '50.5', '19.50')->shares);
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('50', '49.99'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('50', '50.01'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('100'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('100', '0'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('110', '-10'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('33.333', '66.667'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => $percent('abc', '100'));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::own(), '50'),
            new AllocationShare(Recipient::client(self::CLIENT_A), null, Money::of('1', Currency::CZK)),
        ]));
    }

    public function testFixedNeedsARest(): void
    {
        $czk = static fn (string $amount): Money => Money::of($amount, Currency::CZK);
        $client = static fn (string $id, string $amount): AllocationShare => new AllocationShare(Recipient::client($id), null, $czk($amount));

        $fixed = new Allocation(AllocationKind::Fixed, [$client(self::CLIENT_A, '800'), new AllocationShare(Recipient::own(), rest: true)]);
        self::assertCount(2, $fixed->shares);

        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [$client(self::CLIENT_A, '800')]));
        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [new AllocationShare(Recipient::own(), rest: true)]));
        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [
            $client(self::CLIENT_A, '800'),
            new AllocationShare(Recipient::own(), rest: true),
            new AllocationShare(Recipient::client(self::CLIENT_B), rest: true),
        ]));
        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [$client(self::CLIENT_A, '0'), new AllocationShare(Recipient::own(), rest: true)]));
        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [$client(self::CLIENT_A, '-5'), new AllocationShare(Recipient::own(), rest: true)]));
        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client(self::CLIENT_A), null, Money::of('5', Currency::EUR)),
            new AllocationShare(Recipient::own(), rest: true),
        ]));
        $this->assertRefused('fixed_needs_a_rest', fn (): Allocation => new Allocation(AllocationKind::Fixed, [
            $client(self::CLIENT_A, '800'),
            new AllocationShare(Recipient::own(), null, $czk('1'), true),
        ]));
    }

    public function testADuplicateRecipientIsRefused(): void
    {
        $this->assertRefused('duplicate_recipient', fn (): Allocation => new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_A), '50'),
            new AllocationShare(Recipient::client(self::CLIENT_A), '50'),
        ]));
        $this->assertRefused('duplicate_recipient', fn (): Allocation => new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::own(), null, Money::of('10', Currency::CZK)),
            new AllocationShare(Recipient::own(), rest: true),
        ]));
    }

    public function testItRoundTripsThroughAnArray(): void
    {
        $percent = new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_A), '30'),
            new AllocationShare(Recipient::client(self::CLIENT_B), '50'),
            new AllocationShare(Recipient::own(), '20'),
        ]);
        $fixed = new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client(self::CLIENT_A), null, Money::of('800', Currency::CZK)),
            new AllocationShare(Recipient::own(), rest: true),
        ]);
        $whole = new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::own())]);

        foreach ([$percent, $fixed, $whole] as $allocation) {
            $array = $allocation->toArray();
            self::assertEquals($allocation, Allocation::fromArray($array));
            $read = json_decode(json_encode($array, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($read);
            self::assertEquals($allocation, Allocation::fromArray($read));
        }
        self::assertSame(
            ['kind' => 'fixed', 'shares' => [
                ['recipient' => self::CLIENT_A, 'percent' => null, 'amount_minor' => 80000, 'rest' => false],
                ['recipient' => 'own', 'percent' => null, 'amount_minor' => null, 'rest' => true],
            ]],
            $fixed->toArray(),
        );
    }

    public function testAnArrayThatIsNoAllocationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Allocation::fromArray(['kind' => 'sideways', 'shares' => []]);
    }

    /**
     * @param array<string, mixed> $share
     */
    #[DataProvider('brokenArrays')]
    public function testAnArrayWithABrokenShareIsRefused(array $share): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Allocation::fromArray(['kind' => 'whole', 'shares' => [$share]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenArrays(): iterable
    {
        yield 'no recipient' => [['percent' => '100']];
        yield 'a recipient that is not text' => [['recipient' => 5]];
        yield 'a percent that is not text' => [['recipient' => 'own', 'percent' => 100]];
        yield 'an amount that is not whole hellers' => [['recipient' => 'own', 'amount_minor' => '100']];
        yield 'a bad percent with a good amount' => [['recipient' => 'own', 'percent' => 100, 'amount_minor' => 100]];
        yield 'a good percent with a bad amount' => [['recipient' => 'own', 'percent' => '100', 'amount_minor' => 1.5]];
    }

    public function testAShareWithoutRestInAnArrayTakesNotTheRest(): void
    {
        $allocation = Allocation::fromArray(['kind' => 'whole', 'shares' => [['recipient' => 'own']]]);

        self::assertFalse($allocation->shares[0]->rest);
        self::assertNull($allocation->shares[0]->percent);
        self::assertNull($allocation->shares[0]->amountCzk);
    }

    public function testWholeTakesNeitherAnAmountNorTheRest(): void
    {
        $this->assertRefused('whole_needs_one', fn (): Allocation => new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::own(), null, Money::of('1', Currency::CZK))]));
        $this->assertRefused('whole_needs_one', fn (): Allocation => new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::own(), rest: true)]));
    }

    public function testAPercentShareTakesNeitherAnAmountNorTheRest(): void
    {
        $this->assertRefused('percent_must_add_up', fn (): Allocation => new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::own(), '50', Money::of('1', Currency::CZK)),
            new AllocationShare(Recipient::client(self::CLIENT_A), '50'),
        ]));
        $this->assertRefused('percent_must_add_up', fn (): Allocation => new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::own(), '50', rest: true),
            new AllocationShare(Recipient::client(self::CLIENT_A), '50'),
        ]));
    }

    public function testPercent305020Of10001CzkGivesTheOddHellerToTheLastShare(): void
    {
        $allocation = new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_A), '30'),
            new AllocationShare(Recipient::client(self::CLIENT_B), '50'),
            new AllocationShare(Recipient::own(), '20'),
        ]);

        $split = new ShareSplitter()->split($allocation, Money::of('100.01', Currency::CZK));

        self::assertSame([3000, 5000, 2001], array_map(static fn (array $part): int => $part[1]->minor(), $split));
    }

    public function testARecipientIsOwnOrAContact(): void
    {
        self::assertTrue(Recipient::own()->isOwn());
        self::assertNull(Recipient::own()->contactId());
        self::assertSame('own', Recipient::own()->toString());
        self::assertSame(self::CLIENT_A, Recipient::client(self::CLIENT_A)->contactId());
        self::assertSame(self::CLIENT_A, Recipient::client(self::CLIENT_A)->toString());
        self::assertEquals(Recipient::client(self::CLIENT_A), Recipient::fromString(self::CLIENT_A));
        self::assertEquals(Recipient::own(), Recipient::fromString('own'));
    }

    public function testAClientIsKeptInLowerCase(): void
    {
        $recipient = Recipient::client(strtoupper(self::CLIENT_A));

        self::assertSame(self::CLIENT_A, $recipient->contactId());
        self::assertSame(self::CLIENT_A, $recipient->toString());
    }

    #[DataProvider('notContacts')]
    public function testAClientNeedsAUuid(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Recipient::client($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notContacts(): iterable
    {
        yield 'empty' => [''];
        yield 'own' => ['own'];
        yield 'text' => ['Client A'];
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertRefused(string $reason, callable $action): void
    {
        try {
            $action();
        } catch (MarginRefused $refused) {
            self::assertSame($reason, $refused->reason());

            return;
        }
        self::fail('Expected the refusal '.$reason.'.');
    }
}
