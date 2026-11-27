<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Integration\Invoicing;

use Foresi\Banking\Application\Allocation;
use Foresi\Banking\Application\PairingRefused;
use Foresi\Banking\Application\PaymentPairing;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Invoicing\Contract\BankPayment;
use Foresi\Invoicing\Contract\BankPaymentPart;
use Foresi\Invoicing\Contract\BankPaymentRefused;
use Foresi\Invoicing\Contract\BankPayments;
use Foresi\Shared\Domain\Currency;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Movements pay invoices: an incoming one records a payment by bank transfer, an
 * outgoing one a refund, through Invoicing's bank payments, so a payment from the
 * bank follows the rules of any other. The invoice's refusal comes back as a
 * PairingRefused with the problem Invoicing shows for it. Replaces
 * NoPaymentPairing when Invoicing is installed.
 */
#[AsAlias(PaymentPairing::class)]
final readonly class InvoicingPaymentPairing implements PaymentPairing
{
    public function __construct(private BankPayments $payments, private UrlGeneratorInterface $urls)
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function lock(array $documentIds): void
    {
        foreach ($documentIds as $id) {
            $this->guarded(fn () => $this->payments->lock($id));
        }
    }

    public function isSettled(string $documentId, bool $incoming): bool
    {
        return $this->guarded(fn (): bool => $this->payments->isSettled($documentId, !$incoming));
    }

    public function pay(BankMovement $movement, array $allocations): string
    {
        return $this->guarded(fn (): string => $this->payments->record(new BankPayment(
            $movement->id(),
            $movement->bookedOn(),
            $movement->amount(),
            !$movement->isIncoming(),
            array_map(static fn (Allocation $allocation): BankPaymentPart => new BankPaymentPart($allocation->documentId, $allocation->amount, $allocation->settlement), $allocations),
        )));
    }

    public function cancel(string $paymentId): void
    {
        $this->payments->cancel($paymentId);
    }

    public function settlementLimit(Currency $currency): ?int
    {
        return $this->payments->settlementLimit($currency);
    }

    public function isLive(string $movementId): bool
    {
        return $this->payments->hasLivePayment($movementId);
    }

    public function documentsOf(array $paymentIds): array
    {
        return $this->payments->invoiceNumbers($paymentIds);
    }

    public function documentUrl(string $documentId): string
    {
        return $this->urls->generate('app_invoice_show', ['id' => $documentId]);
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function guarded(callable $call): mixed
    {
        try {
            return $call();
        } catch (BankPaymentRefused $refused) {
            throw match ($refused->reason) {
                BankPaymentRefused::UNKNOWN_INVOICE => new PairingRefused(PairingRefused::UNKNOWN_DOCUMENT),
                BankPaymentRefused::CURRENCY_MISMATCH => new PairingRefused(PairingRefused::CURRENCY_MISMATCH, $refused->problem, $refused->parameters),
                BankPaymentRefused::TRASHED => new PairingRefused(PairingRefused::DOCUMENT_TRASHED, $refused->problem, $refused->parameters),
                default => new PairingRefused(PairingRefused::PAYMENT_REFUSED, $refused->problem, $refused->parameters),
            };
        }
    }
}
