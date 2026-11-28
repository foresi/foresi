<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * How the cost of a line or of a document is split among recipients, in the
 * order the person typed them. It is valid or it does not exist: the
 * constructor refuses what no split can follow (MarginRefused).
 *
 * Percent values are decimal strings with up to two decimals, each above 0,
 * adding up to exactly 100.
 */
final readonly class Allocation
{
    /**
     * @param list<AllocationShare> $shares
     *
     * @throws MarginRefused
     */
    public function __construct(public AllocationKind $kind, public array $shares)
    {
        match ($kind) {
            AllocationKind::Whole => $this->checkWhole(),
            AllocationKind::Percent => $this->checkPercent(),
            AllocationKind::Fixed => $this->checkFixed(),
        };
        $recipients = array_map(static fn (AllocationShare $share): string => $share->recipient->toString(), $shares);
        if (\count($recipients) !== \count(array_unique($recipients))) {
            throw new MarginRefused(MarginRefused::DUPLICATE_RECIPIENT, 'A recipient may appear only once in an allocation.');
        }
    }

    /**
     * @return array{kind: string, shares: list<array{recipient: string, percent: ?string, amount_minor: ?int, rest: bool}>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'shares' => array_map(static fn (AllocationShare $share): array => [
                'recipient' => $share->recipient->toString(),
                'percent' => $share->percent,
                'amount_minor' => $share->amountCzk?->minor(),
                'rest' => $share->rest,
            ], $this->shares),
        ];
    }

    /**
     * @param array<mixed> $data the form of toArray(), also as read back from JSON
     *
     * @throws \InvalidArgumentException when the data is no allocation
     * @throws MarginRefused             when the allocation it describes is refused
     */
    public static function fromArray(array $data): self
    {
        $kind = \is_string($data['kind'] ?? null) ? AllocationKind::tryFrom($data['kind']) : null;
        $shares = $data['shares'] ?? null;
        if (null === $kind || !\is_array($shares)) {
            throw new \InvalidArgumentException('An allocation has a kind and shares.');
        }

        $built = [];
        foreach ($shares as $share) {
            if (!\is_array($share) || !\is_string($share['recipient'] ?? null)) {
                throw new \InvalidArgumentException('A share names its recipient.');
            }
            $percent = $share['percent'] ?? null;
            $minor = $share['amount_minor'] ?? null;
            if ((null !== $percent && !\is_string($percent)) || (null !== $minor && !\is_int($minor))) {
                throw new \InvalidArgumentException('A share has a percent as text and an amount in whole hellers.');
            }
            $built[] = new AllocationShare(
                Recipient::fromString($share['recipient']),
                $percent,
                null === $minor ? null : Money::ofMinor($minor, Currency::of('CZK')),
                true === ($share['rest'] ?? false),
            );
        }

        return new self($kind, $built);
    }

    private function checkWhole(): void
    {
        if (1 !== \count($this->shares) || null !== $this->shares[0]->percent || $this->shares[0]->amountCzk instanceof Money || $this->shares[0]->rest) {
            throw new MarginRefused(MarginRefused::WHOLE_NEEDS_ONE, 'A whole allocation has exactly one recipient and no percent or amount.');
        }
    }

    private function checkPercent(): void
    {
        $sum = 0;
        foreach ($this->shares as $share) {
            $hundredths = null === $share->percent ? null : Percent::hundredths($share->percent);
            if (null === $hundredths) {
                throw $this->notHundred();
            }
            if (null !== $share->amountCzk || $share->rest || $hundredths <= 0) {
                throw $this->notHundred();
            }
            $sum += $hundredths;
        }
        if (\count($this->shares) < 2 || Percent::WHOLE !== $sum) {
            throw $this->notHundred();
        }
    }

    private function checkFixed(): void
    {
        $rests = 0;
        $fixed = 0;
        foreach ($this->shares as $share) {
            if (null !== $share->percent) {
                throw $this->needsARest();
            }
            if ($share->rest) {
                if (null !== $share->amountCzk) {
                    throw $this->needsARest();
                }
                ++$rests;
                continue;
            }
            if (null === $share->amountCzk || Currency::of('CZK') !== $share->amountCzk->currency() || $share->amountCzk->minor() <= 0) {
                throw $this->needsARest();
            }
            ++$fixed;
        }
        if (1 !== $rests || $fixed < 1) {
            throw $this->needsARest();
        }
    }

    private function notHundred(): MarginRefused
    {
        return new MarginRefused(MarginRefused::PERCENT_MUST_ADD_UP, 'A percent allocation has two or more recipients, each above 0 with up to two decimals, adding up to exactly 100.');
    }

    private function needsARest(): MarginRefused
    {
        return new MarginRefused(MarginRefused::FIXED_NEEDS_A_REST, 'A fixed allocation has one or more recipients with a positive amount in CZK and exactly one recipient that takes the rest.');
    }
}
