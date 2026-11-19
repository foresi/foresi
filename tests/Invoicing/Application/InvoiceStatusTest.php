<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\InvoiceStatus;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Contract\DocumentKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(InvoiceStatus::class)]
final class InvoiceStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool, InvoiceStatus, string}>
     */
    public static function states(): iterable
    {
        yield 'issued' => [false, false, false, InvoiceStatus::Issued, 'gray'];
        yield 'sent' => [true, false, false, InvoiceStatus::Sent, 'gray'];
        yield 'overdue' => [false, true, false, InvoiceStatus::Overdue, 'red'];
        yield 'overdue outranks sent' => [true, true, false, InvoiceStatus::Overdue, 'red'];
        yield 'the trash outranks everything' => [true, true, true, InvoiceStatus::Trashed, 'amber'];
    }

    #[DataProvider('states')]
    public function testShowsTheMostRelevantState(bool $sent, bool $overdue, bool $trashed, InvoiceStatus $status, string $tone): void
    {
        self::assertSame($status, InvoiceStatus::of($sent, $overdue, $trashed, PaymentState::Unpaid));
        self::assertSame($tone, $status->tone());
    }

    /**
     * Every combination of the four facts, each against the order
     * Trashed > Overpaid > Paid > Overdue > PartlyPaid > Sent > Issued.
     *
     * @return iterable<string, array{bool, bool, bool, PaymentState, InvoiceStatus}>
     */
    public static function combinations(): iterable
    {
        foreach ([false, true] as $trashed) {
            foreach (PaymentState::cases() as $payment) {
                foreach ([false, true] as $overdue) {
                    foreach ([false, true] as $sent) {
                        $expected = match (true) {
                            $trashed => InvoiceStatus::Trashed,
                            PaymentState::ToRefund === $payment => InvoiceStatus::ToRefund,
                            PaymentState::Overpaid === $payment => InvoiceStatus::Overpaid,
                            PaymentState::Paid === $payment => InvoiceStatus::Paid,
                            $overdue => InvoiceStatus::Overdue,
                            PaymentState::PartlyPaid === $payment => InvoiceStatus::PartlyPaid,
                            $sent => InvoiceStatus::Sent,
                            default => InvoiceStatus::Issued,
                        };
                        $name = \sprintf('%s, %s, %s, %s', $trashed ? 'trashed' : 'kept', $payment->value, $overdue ? 'past due' : 'in time', $sent ? 'sent' : 'not sent');

                        yield $name => [$sent, $overdue, $trashed, $payment, $expected];
                    }
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function testStatusOrderTrashedOverpaidPaidOverduePartlyPaidSentIssued(bool $sent, bool $overdue, bool $trashed, PaymentState $payment, InvoiceStatus $expected): void
    {
        self::assertSame($expected, InvoiceStatus::of($sent, $overdue, $trashed, $payment));
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

    public function testTheLifecycleRunsIssuedSentPaid(): void
    {
        self::assertSame(InvoiceStatus::Issued, InvoiceStatus::of(false, false, false, PaymentState::Unpaid));
        self::assertSame(InvoiceStatus::Sent, InvoiceStatus::of(true, false, false, PaymentState::Unpaid));
        // Paid wins over sent, and a paid document is never overdue.
        self::assertSame(InvoiceStatus::Paid, InvoiceStatus::of(true, true, false, PaymentState::Paid));
        // Overdue applies to an unpaid one, sent or not, and to one partly paid.
        self::assertSame(InvoiceStatus::Overdue, InvoiceStatus::of(false, true, false, PaymentState::Unpaid));
        self::assertSame(InvoiceStatus::Overdue, InvoiceStatus::of(true, true, false, PaymentState::PartlyPaid));
        self::assertSame(InvoiceStatus::PartlyPaid, InvoiceStatus::of(true, false, false, PaymentState::PartlyPaid));
    }

    public function testOnlyTheMoneyThatCameInIsGreen(): void
    {
        self::assertSame('gray', InvoiceStatus::Issued->tone());
        self::assertSame('gray', InvoiceStatus::Sent->tone());
        self::assertSame('green', InvoiceStatus::Paid->tone());
    }

    public function testTheLabelAgreesWithTheKind(): void
    {
        self::assertSame('invoice.list.status.sent', InvoiceStatus::Sent->labelKey(DocumentKind::Invoice));
        self::assertSame('invoice.list.status.paid', InvoiceStatus::Paid->labelKey(DocumentKind::Advance));
        self::assertSame('invoice.list.document_status.sent', InvoiceStatus::Sent->labelKey(DocumentKind::PaymentTaxDocument));
        self::assertSame('invoice.list.document_status.issued', InvoiceStatus::Issued->labelKey(DocumentKind::CorrectionDocument));
    }

    public function testEveryLabelIsTranslated(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $messages = Yaml::parseFile(\dirname(__DIR__, 3).'/src/Invoicing/translations/messages+intl-icu.'.$locale.'.yaml');
            foreach (DocumentKind::cases() as $kind) {
                foreach (InvoiceStatus::cases() as $status) {
                    $value = $messages;
                    foreach (explode('.', $status->labelKey($kind)) as $part) {
                        $value = \is_array($value) ? ($value[$part] ?? null) : null;
                    }
                    self::assertIsString($value, $locale.': '.$status->labelKey($kind));
                }
            }
        }
        // Czech agrees in gender: an invoice is sent "Odeslaná", a tax document "Odeslaný".
        $translator = new Translator('cs');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3).'/src/Invoicing/translations/messages+intl-icu.cs.yaml', 'cs', 'messages+intl-icu');
        self::assertSame('Odeslaná', $translator->trans(InvoiceStatus::Sent->labelKey(DocumentKind::Invoice)));
        self::assertSame('Odeslaný', $translator->trans(InvoiceStatus::Sent->labelKey(DocumentKind::PaymentTaxDocument)));
    }

    public function testACorrectionIsOnlyIssuedOrTrashed(): void
    {
        self::assertSame(InvoiceStatus::Issued, InvoiceStatus::ofCorrection(false));
        self::assertSame(InvoiceStatus::Trashed, InvoiceStatus::ofCorrection(true));
    }
}
