<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Domain;

use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ShareSplitterTest extends TestCase
{
    private const string CLIENT_A = '0197a1de-0000-7000-8000-00000000000a';
    private const string CLIENT_B = '0197a1de-0000-7000-8000-00000000000b';

    public function testAWholeAllocationGivesTheWholeBase(): void
    {
        $split = $this->split(new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client(self::CLIENT_A))]), '650');

        self::assertSame([self::CLIENT_A.'=65000'], $split);
    }

    public function testPercentTakesTheFloorOfEachShareAndTheRemainderGoesToTheLast(): void
    {
        $allocation = new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_A), '33.33'),
            new AllocationShare(Recipient::client(self::CLIENT_B), '33.33'),
            new AllocationShare(Recipient::own(), '33.34'),
        ]);

        // 0.10 CZK = 10 hellers: floor(3.333) = 3, 3, and the last takes the remaining 4.
        self::assertSame([self::CLIENT_A.'=3', self::CLIENT_B.'=3', 'own=4'], $this->split($allocation, '0.10'));
        self::assertSame([self::CLIENT_A.'=333300', self::CLIENT_B.'=333300', 'own=333400'], $this->split($allocation, '10000'));
    }

    public function testPercentKeepsTheTypedOrderWhateverTheSizes(): void
    {
        $allocation = new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::own(), '70'),
            new AllocationShare(Recipient::client(self::CLIENT_A), '30'),
        ]);

        self::assertSame(['own=7000', self::CLIENT_A.'=3001'], $this->split($allocation, '100.01'));
    }

    public function testFixedAmountOnALineCapsAndGivesTheRest(): void
    {
        $allocation = new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client(self::CLIENT_A), null, Money::of('800', Currency::CZK)),
            new AllocationShare(Recipient::own(), rest: true),
        ]);

        self::assertSame([self::CLIENT_A.'=65000', 'own=0'], $this->split($allocation, '650'));
        self::assertSame([self::CLIENT_A.'=80000', 'own=20000'], $this->split($allocation, '1000'));
    }

    public function testFixedAmountsAreTakenInOrderEachCappedAtWhatIsLeft(): void
    {
        $allocation = new Allocation(AllocationKind::Fixed, [
            new AllocationShare(Recipient::client(self::CLIENT_A), null, Money::of('400', Currency::CZK)),
            new AllocationShare(Recipient::own(), rest: true),
            new AllocationShare(Recipient::client(self::CLIENT_B), null, Money::of('400', Currency::CZK)),
        ]);

        self::assertSame([self::CLIENT_A.'=40000', 'own=0', self::CLIENT_B.'=25000'], $this->split($allocation, '650'));
        self::assertSame([self::CLIENT_A.'=40000', 'own=20000', self::CLIENT_B.'=40000'], $this->split($allocation, '1000'));
    }

    public function testACreditNoteGivesNegativeShares(): void
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

        self::assertSame([self::CLIENT_A.'=-3000', self::CLIENT_B.'=-5000', 'own=-2001'], $this->split($percent, '-100.01'));
        self::assertSame([self::CLIENT_A.'=-65000', 'own=0'], $this->split($fixed, '-650'));
        self::assertSame([self::CLIENT_A.'=-80000', 'own=-20000'], $this->split($fixed, '-1000'));
        self::assertSame(['own=-65000'], $this->split($whole, '-650'));
    }

    public function testASplitAlwaysAddsUpToTheBase(): void
    {
        $allocation = new Allocation(AllocationKind::Percent, [
            new AllocationShare(Recipient::client(self::CLIENT_A), '12.34'),
            new AllocationShare(Recipient::client(self::CLIENT_B), '45.66'),
            new AllocationShare(Recipient::own(), '42'),
        ]);

        foreach (['0.01', '0.07', '1234.56', '99999999.99', '-0.03'] as $base) {
            $sum = array_sum(array_map(static fn (array $part): int => $part[1]->minor(), new ShareSplitter()->split($allocation, Money::of($base, Currency::CZK))));
            self::assertSame(Money::of($base, Currency::CZK)->minor(), $sum, $base);
        }
    }

    /**
     * @return list<string> "recipient=hellers" in order
     */
    private function split(Allocation $allocation, string $base): array
    {
        return array_map(
            static fn (array $part): string => $part[0]->toString().'='.$part[1]->minor(),
            new ShareSplitter()->split($allocation, Money::of($base, Currency::CZK)),
        );
    }
}
