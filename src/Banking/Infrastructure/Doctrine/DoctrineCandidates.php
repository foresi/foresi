<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Banking\Domain\BankMovement;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\PaymentDirection;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One query through the ORM, so the tenant filter narrows the invoices and the
 * payments both. What is paid is summed as the invoice list sums it: amounts and
 * settlements of allocations whose payment is not cancelled, a refund's below zero.
 */
final readonly class DoctrineCandidates implements Candidates
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function withSymbol(string $firmId, string $variableSymbol): array
    {
        return $this->candidates(
            'i.firm = :firm AND i.deletedAt IS NULL AND TRIM(LEADING \'0\' FROM i.variableSymbol) = :symbol',
            ['firm' => $firmId, 'symbol' => $variableSymbol],
        );
    }

    public function open(string $firmId, Currency $currency, string $text, array $ids, int $limit): array
    {
        $where = 'i.firm = :firm AND i.deletedAt IS NULL AND i.currency = :currency';
        $parameters = ['firm' => $firmId, 'currency' => $currency->value];
        $found = [];
        $text = trim($text);
        if ('' !== $text) {
            $found[] = "LOWER(i.number) LIKE :text ESCAPE '!'";
            $found[] = "LOWER(i.customer.name) LIKE :text ESCAPE '!'";
            $found[] = "i.variableSymbol LIKE :text ESCAPE '!'";
            $parameters['text'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%';
        }
        if ([] !== $ids) {
            $found[] = 'i.id IN (:ids)';
            $parameters['ids'] = $ids;
        }
        if ('' !== $text || [] !== $ids) {
            $where .= ' AND ('.implode(' OR ', $found).')';
        }

        // Settled ones are read and left out: what is left to pay is not a column.
        return \array_slice($this->candidates($where, $parameters), 0, max(0, $limit));
    }

    public function refunds(string $firmId, ?string $variableSymbol): array
    {
        $where = 'i.firm = :firm AND i.deletedAt IS NULL';
        $parameters = ['firm' => $firmId];
        if (null !== $variableSymbol) {
            $where .= ' AND (TRIM(LEADING \'0\' FROM i.variableSymbol) = :symbol OR EXISTS (SELECT 1 FROM '.Invoice::class.' cs'
                .' WHERE cs.correctedInvoiceId = i.id AND cs.deletedAt IS NULL AND TRIM(LEADING \'0\' FROM cs.variableSymbol) = :symbol))';
            $parameters['symbol'] = $variableSymbol;
        }
        $candidates = [];
        foreach ($this->rows($where, $parameters) as $row) {
            $toRefund = $this->toRefund($row);
            if ($toRefund > 0) {
                // Money sent back before a credit note was written is for something else: the later date counts.
                $issued = null === $row['correctedOn'] ? $row['issueDate'] : max($row['issueDate'], new \DateTimeImmutable($row['correctedOn']));
                $candidates[] = new Candidate($row['id'], $row['number'], $row['dueDate'], Money::ofMinor($toRefund, $row['currency']), $row['customer'], $issued);
            }
        }

        return $candidates;
    }

    public function paidFrom(string $firmId, ?string $counterIban, ?string $counterAccount): array
    {
        [$field, $value] = null !== $counterIban && '' !== $counterIban ? ['counterIban', $counterIban] : ['counterAccount', $counterAccount];
        if (null === $value || '' === $value) {
            return [];
        }
        /** @var list<array{id: string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(a.invoice) AS id FROM '.PaymentAllocation::class.' a JOIN a.payment p, '.BankMovement::class.' m '
            .'WHERE m.id = p.bankMovementId AND p.cancelledAt IS NULL AND p.direction = :incoming AND m.firm = :firm AND m.'.$field.' = :value',
        )
            ->setParameter('incoming', PaymentDirection::Incoming)
            ->setParameter('firm', $firmId)
            ->setParameter('value', $value)
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id'], $rows);
    }

    /**
     * As InvoicePayments::toRefundOf() counts it: what was paid on an advance no final
     * invoice deducts; on anything else, what was paid above what is due after the
     * corrections, on an invoice with something to pay or one its advances exceed.
     *
     * @param array{kind: DocumentKind, payable: int|numeric-string, paid: int|numeric-string, corrections: int|numeric-string, deductsAdvances: bool, deducted: int|numeric-string} $row
     */
    private function toRefund(array $row): int
    {
        $payable = (int) $row['payable'];
        $paid = (int) $row['paid'];
        if (DocumentKind::Advance === $row['kind']) {
            return 0 === (int) $row['deducted'] ? $paid : 0;
        }
        $refundable = $payable > 0 || $row['deductsAdvances'];

        return $refundable ? $paid - $payable - (int) $row['corrections'] : 0;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<Candidate>
     */
    private function candidates(string $where, array $parameters): array
    {
        $candidates = [];
        foreach ($this->rows($where, $parameters) as $row) {
            // Overpaid or credited invoices have nothing left: no candidate either.
            $remaining = (int) $row['payable'] + (int) $row['corrections'] - (int) $row['paid'];
            if ($remaining > 0) {
                $candidates[] = new Candidate($row['id'], $row['number'], $row['dueDate'], Money::ofMinor($remaining, $row['currency']), $row['customer'], $row['issueDate']);
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array{id: string, number: string, kind: DocumentKind, dueDate: \DateTimeImmutable, issueDate: \DateTimeImmutable, payable: int|numeric-string, currency: Currency, customer: string, deductsAdvances: bool, deducted: int|numeric-string, paid: int|numeric-string, corrections: int|numeric-string, correctedOn: string|null}>
     */
    private function rows(string $where, array $parameters): array
    {
        // Only what asks for money: a tax document on payment is paid by the payment it taxes.
        $where .= ' AND i.kind IN (:payableKinds)';
        $parameters['payableKinds'] = array_values(array_map(static fn (DocumentKind $kind): string => $kind->value, array_filter(DocumentKind::cases(), static fn (DocumentKind $kind): bool => $kind->isPayable())));
        $query = $this->em->createQuery(
            'SELECT i.id AS id, i.number AS number, i.kind AS kind, i.dueDate AS dueDate, i.issueDate AS issueDate, i.payableMinor AS payable, i.currency AS currency, i.customer.name AS customer, i.deductsAdvances AS deductsAdvances, '
            .'CASE WHEN i.deductedByInvoiceId IS NULL THEN 0 ELSE 1 END AS deducted, '
            .'(SELECT COALESCE(SUM(a.amountMinor + a.settlementMinor), 0) FROM '.PaymentAllocation::class.' a JOIN a.payment p WHERE a.invoice = i AND p.cancelledAt IS NULL) AS paid, '
            // A credit note lowers what is left and a debit note raises it; neither is a candidate itself.
            .'(SELECT COALESCE(SUM(c.payableMinor), 0) FROM '.Invoice::class.' c WHERE c.correctedInvoiceId = i.id AND c.deletedAt IS NULL) AS corrections, '
            .'(SELECT MAX(cd.issueDate) FROM '.Invoice::class.' cd WHERE cd.correctedInvoiceId = i.id AND cd.deletedAt IS NULL) AS correctedOn '
            .'FROM '.Invoice::class.' i '
            .'WHERE '.$where.' '
            .'ORDER BY i.dueDate, i.number, i.id',
        );
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        /** @var list<array{id: string, number: string, kind: DocumentKind, dueDate: \DateTimeImmutable, issueDate: \DateTimeImmutable, payable: int|numeric-string, currency: Currency, customer: string, deductsAdvances: bool, deducted: int|numeric-string, paid: int|numeric-string, corrections: int|numeric-string, correctedOn: string|null}> $rows */
        $rows = $query->getArrayResult();

        return $rows;
    }
}
