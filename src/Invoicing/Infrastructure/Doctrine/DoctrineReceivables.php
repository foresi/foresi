<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\Receivable;
use Foresi\Invoicing\Application\Receivables;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * One query for all open documents, with what is due and paid summed in SQL by the
 * same subqueries as the tabs of the list; the days past due are counted in PHP, from
 * today in the country of each issuer, as the list marks a row overdue.
 */
final readonly class DoctrineReceivables implements Receivables
{
    public function __construct(
        private EntityManagerInterface $em,
        private InvoicingCountries $countries,
        private ClockInterface $clock,
    ) {
    }

    public function firms(): array
    {
        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->em->createQuery('SELECT DISTINCT f.id AS id, f.name AS name FROM '.Invoice::class.' i JOIN i.firm f ORDER BY f.name, f.id')->getArrayResult();

        return array_column($rows, 'name', 'id');
    }

    public function open(?string $firmId): array
    {
        $due = DoctrineInvoicePayments::dueOfInvoiceDql('d');
        $paid = DoctrineInvoicePayments::paidOfInvoiceDql('p');
        $query = $this->em->createQuery('SELECT i.customer.name AS customer, i.dueDate AS dueDate, i.issuer.address.countryCode AS country, i.exchangeRate AS rate,'
            .' '.$due.' AS due, '.$paid.' AS paid FROM '.Invoice::class.' i'
            .' WHERE i.deletedAt IS NULL AND i.kind IN (:kinds) AND '.DoctrineInvoicePayments::dueOfInvoiceDql('dw').' > '.DoctrineInvoicePayments::paidOfInvoiceDql('pw')
            .(null === $firmId ? '' : ' AND i.firm = :firm')
            .' ORDER BY i.numberKey, i.id')
            ->setParameter('kinds', [DocumentKind::Invoice->value, DocumentKind::Advance->value]);
        if (null !== $firmId) {
            $query->setParameter('firm', $firmId);
        }

        /** @var list<array{customer: string, dueDate: \DateTimeImmutable, country: string, rate: string, due: int|numeric-string, paid: int|numeric-string}> $rows */
        $rows = $query->getArrayResult();
        $todays = [];
        $receivables = [];
        foreach ($rows as $row) {
            $today = $todays[$row['country']] ??= $this->todayIn($row['country']);
            $days = $row['dueDate']->format('Y-m-d') < $today ? new \DateTimeImmutable($row['dueDate']->format('Y-m-d'))->diff(new \DateTimeImmutable($today))->days : 0;
            $remaining = Money::ofMinor((int) $row['due'] - (int) $row['paid'], Currency::CZK)->multipliedBy($row['rate']);
            $receivables[] = new Receivable($row['customer'], $remaining, $days);
        }

        return $receivables;
    }

    /**
     * Today as Y-m-d in a country; one whose rules are gone has the day of the server.
     */
    private function todayIn(string $countryCode): string
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            return $now->setTimezone($this->countries->for($countryCode)->timezone())->format('Y-m-d');
        } catch (UnsupportedCountry) {
            return $now->format('Y-m-d');
        }
    }
}
