<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\LevyPayment;
use Foresi\TaxesAndContributions\Contract\LevyPaymentSource;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Which due payments the bank has paid (B4). Each payment takes the match
 * rule of its authority in the profile: its account and variable symbol. A
 * movement that meets the rule pays one payment of the authority, in the
 * movement's direction, that could be paid on its day: the one it is on time
 * for with the same amount, else the oldest debt, as an authority credits it.
 * A payment left unpaid after its due day is overdue; the days are those of
 * the instance's zone.
 */
final readonly class DuePayments
{
    /**
     * @param iterable<LevyPaymentSource> $banks Cash flow's, when it is installed
     */
    public function __construct(
        private iterable $banks = [],
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<DuePayment> $payments expected, of one or more consecutive years
     *
     * @return array{list<DuePayment>, bool} the payments with their rules and states, and whether a bank source failed
     */
    public function stated(array $payments, ProfileView $profile, \DateTimeImmutable $today): array
    {
        $rules = [];
        foreach ($profile->authorities as $account) {
            $rules[$account->authority] = $account;
        }
        $payments = array_map(static function (DuePayment $payment) use ($rules): DuePayment {
            $rule = $rules[$payment->authority->value] ?? null;

            return $rule instanceof AuthorityAccountView ? $payment->withMatchRule($rule->account, $rule->variableSymbol) : $payment;
        }, $payments);
        [$movements, $failed] = $this->movements($payments, array_values($rules), $today);
        $paid = $this->paid($payments, $movements);
        $day = $today->format('Y-m-d');
        $stated = [];
        foreach ($payments as $index => $payment) {
            $movement = $paid[$index] ?? null;
            $stated[] = match (true) {
                $movement instanceof LevyPayment => $payment->withState(DuePayment::STATE_PAID, $movement->id, $movement->on),
                $payment->dueOn->format('Y-m-d') < $day => $payment->withState(DuePayment::STATE_OVERDUE),
                default => $payment,
            };
        }

        return [$stated, $failed];
    }

    /**
     * The movements that meet a rule, from the first day a payment could be
     * paid to today.
     *
     * @param list<DuePayment>           $payments
     * @param list<AuthorityAccountView> $rules
     *
     * @return array{list<LevyPayment>, bool}
     */
    private function movements(array $payments, array $rules, \DateTimeImmutable $today): array
    {
        if ([] === $rules || [] === $payments) {
            return [[], false];
        }
        $from = min(array_map(static fn (DuePayment $payment): \DateTimeImmutable => $payment->payableFrom, $payments));
        if ($from > $today) {
            return [[], false];
        }
        $movements = [];
        $failed = false;
        foreach ($this->banks as $bank) {
            try {
                $found = $bank->paymentsBetween($from, $today, $rules);
                array_push($movements, ...$found->payments);
                // A bank source that failed was logged where it failed; it gives no movement.
                $failed = $failed || [] !== $found->unavailable;
            } catch (\Throwable $failure) {
                // The contract says a failure, but a bank of another module may still throw.
                $this->logger->error('The bank movements of the levies could not be read.', ['exception' => $failure]);
                $failed = true;
            }
        }
        usort($movements, static fn (LevyPayment $a, LevyPayment $b): int => [$a->on->format('Y-m-d'), $a->id] <=> [$b->on->format('Y-m-d'), $b->id]);

        return [$movements, $failed];
    }

    /**
     * The movement that pays each payment, by the payment's index. Of the
     * payments a movement could pay, it pays one of the same amount first, one
     * it is on time for next, and of those the one due first.
     *
     * @param list<DuePayment>  $payments
     * @param list<LevyPayment> $movements oldest first
     *
     * @return array<int, LevyPayment>
     */
    private function paid(array $payments, array $movements): array
    {
        $paid = [];
        foreach ($movements as $movement) {
            $on = $movement->on->format('Y-m-d');
            $chosen = null;
            $best = null;
            foreach ($payments as $index => $payment) {
                if (isset($paid[$index]) || $payment->authority !== $movement->authority || !$payment->hasMatchRule() || $payment->comesIn() !== $movement->incoming
                    || $payment->payableFrom->format('Y-m-d') > $on) {
                    continue;
                }
                $same = $payment->amount->equals($movement->amount);
                $onTime = $on <= $payment->dueOn->format('Y-m-d');
                $rank = [!$same, !$onTime, $payment->dueOn->format('Y-m-d')];
                if (null === $best || $rank < $best) {
                    [$chosen, $best] = [$index, $rank];
                }
            }
            if (null !== $chosen) {
                $paid[$chosen] = $movement;
            }
        }

        return $paid;
    }
}
