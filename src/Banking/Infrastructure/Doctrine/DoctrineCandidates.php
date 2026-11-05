<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentAllocation;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One query through the ORM, so the tenant filter narrows the invoices and the
 * payments both. What is paid is summed as the invoice list sums it: amounts and
 * settlements of allocations whose payment is not cancelled.
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

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<Candidate>
     */
    private function candidates(string $where, array $parameters): array
    {
        // Only what asks for money: a tax document on payment is paid by the payment it taxes.
        $where .= ' AND i.kind IN (:payableKinds)';
        $parameters['payableKinds'] = array_values(array_map(static fn (DocumentKind $kind): string => $kind->value, array_filter(DocumentKind::cases(), static fn (DocumentKind $kind): bool => $kind->isPayable())));
        $query = $this->em->createQuery(
            'SELECT i.id AS id, i.number AS number, i.dueDate AS dueDate, i.issueDate AS issueDate, i.payableMinor AS payable, i.currency AS currency, i.customer.name AS customer, '
            .'(SELECT COALESCE(SUM(a.amountMinor + a.settlementMinor), 0) FROM '.PaymentAllocation::class.' a JOIN a.payment p WHERE a.invoice = i AND p.cancelledAt IS NULL) AS paid, '
            // A credit note lowers what is left and a debit note raises it; neither is a candidate itself.
            .'(SELECT COALESCE(SUM(c.payableMinor), 0) FROM '.Invoice::class.' c WHERE c.correctedInvoiceId = i.id AND c.deletedAt IS NULL) AS corrections '
            .'FROM '.Invoice::class.' i '
            .'WHERE '.$where.' '
            .'ORDER BY i.dueDate, i.number, i.id',
        );
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        /** @var list<array{id: string, number: string, dueDate: \DateTimeImmutable, issueDate: \DateTimeImmutable, payable: int|numeric-string, currency: Currency, customer: string, paid: int|numeric-string, corrections: int|numeric-string}> $rows */
        $rows = $query->getArrayResult();

        $candidates = [];
        foreach ($rows as $row) {
            // Overpaid or credited invoices have nothing left: no candidate either.
            $remaining = (int) $row['payable'] + (int) $row['corrections'] - (int) $row['paid'];
            if ($remaining > 0) {
                $candidates[] = new Candidate($row['id'], $row['number'], $row['dueDate'], Money::ofMinor($remaining, $row['currency']), $row['customer'], $row['issueDate']);
            }
        }

        return $candidates;
    }
}
