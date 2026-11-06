<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\PaymentState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvoiceStatus::class)]
final class InvoiceStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool, InvoiceStatus, string}>
     */
    public static function states(): iterable
    {
        yield 'issued' => [false, false, false, InvoiceStatus::Issued, 'gray'];
        yield 'delivered' => [true, false, false, InvoiceStatus::Delivered, 'green'];
        yield 'overdue' => [false, true, false, InvoiceStatus::Overdue, 'red'];
        yield 'overdue outranks delivered' => [true, true, false, InvoiceStatus::Overdue, 'red'];
        yield 'the trash outranks everything' => [true, true, true, InvoiceStatus::Trashed, 'amber'];
    }

    #[DataProvider('states')]
    public function testShowsTheMostRelevantState(bool $delivered, bool $overdue, bool $trashed, InvoiceStatus $status, string $tone): void
    {
        self::assertSame($status, InvoiceStatus::of($delivered, $overdue, $trashed, PaymentState::Unpaid));
        self::assertSame($tone, $status->tone());
    }

    /**
     * Every combination of the four facts, each against the order
     * Trashed > Overpaid > Paid > Overdue > PartlyPaid > Delivered > Issued.
     *
     * @return iterable<string, array{bool, bool, bool, PaymentState, InvoiceStatus}>
     */
    public static function combinations(): iterable
    {
        foreach ([false, true] as $trashed) {
            foreach (PaymentState::cases() as $payment) {
                foreach ([false, true] as $overdue) {
                    foreach ([false, true] as $delivered) {
                        $expected = match (true) {
                            $trashed => InvoiceStatus::Trashed,
                            PaymentState::ToRefund === $payment => InvoiceStatus::ToRefund,
                            PaymentState::Overpaid === $payment => InvoiceStatus::Overpaid,
                            PaymentState::Paid === $payment => InvoiceStatus::Paid,
                            $overdue => InvoiceStatus::Overdue,
                            PaymentState::PartlyPaid === $payment => InvoiceStatus::PartlyPaid,
                            $delivered => InvoiceStatus::Delivered,
                            default => InvoiceStatus::Issued,
                        };
                        $name = \sprintf('%s, %s, %s, %s', $trashed ? 'trashed' : 'kept', $payment->value, $overdue ? 'past due' : 'in time', $delivered ? 'delivered' : 'not delivered');

                        yield $name => [$delivered, $overdue, $trashed, $payment, $expected];
                    }
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function testStatusOrderTrashedOverpaidPaidOverduePartlyPaidDeliveredIssued(bool $delivered, bool $overdue, bool $trashed, PaymentState $payment, InvoiceStatus $expected): void
    {
        self::assertSame($expected, InvoiceStatus::of($delivered, $overdue, $trashed, $payment));
    }

    public function testThePaymentStateMustBeGiven(): void
    {
        // A caller that forgot it would show a paid invoice as overdue.
        $payment = new \ReflectionMethod(InvoiceStatus::class, 'of')->getParameters()[3];

        self::assertSame('payment', $payment->getName());
        self::assertFalse($payment->isOptional());
    }

    public function testThePaymentStatesHaveTheirTones(): void
    {
        self::assertSame('green', InvoiceStatus::Paid->tone());
        self::assertSame('amber', InvoiceStatus::PartlyPaid->tone());
        self::assertSame('amber', InvoiceStatus::Overpaid->tone());
        self::assertSame('amber', InvoiceStatus::ToRefund->tone());
    }

    public function testACorrectionIsOnlyIssuedOrTrashed(): void
    {
        self::assertSame(InvoiceStatus::Issued, InvoiceStatus::ofCorrection(false));
        self::assertSame(InvoiceStatus::Trashed, InvoiceStatus::ofCorrection(true));
    }
}
