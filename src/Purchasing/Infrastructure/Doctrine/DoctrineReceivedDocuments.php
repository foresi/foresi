<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentLine;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineReceivedDocuments implements ReceivedDocuments
{
    // A write that changes nothing: the row lock alone, the same on SQLite and PostgreSQL.
    private const string LOCK = 'UPDATE received_document SET version = version WHERE id = :id';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?ReceivedDocument
    {
        return Id::isValid($id) ? $this->entityManager->find(ReceivedDocument::class, $id) : null;
    }

    public function findMany(array $ids): array
    {
        $ids = array_values(array_filter($ids, Id::isValid(...)));
        if ([] === $ids) {
            return [];
        }

        return $this->entityManager->createQuery('SELECT d FROM '.ReceivedDocument::class.' d WHERE d.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getResult();
    }

    public function lock(string $id): ReceivedDocument
    {
        if (!Id::isValid($id) || 1 !== $this->entityManager->getConnection()->executeStatement(self::LOCK, ['id' => $id])) {
            throw new UnknownReceivedDocument();
        }
        // What this process loaded before may be older than the lock, so it is read again.
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($id, ReceivedDocument::class);
        if ($loaded instanceof ReceivedDocument) {
            // Its payments and lines loaded before would point at the detached copy, so they go too.
            $map = $this->entityManager->getUnitOfWork()->getIdentityMap();
            foreach ($map[ReceivedDocumentPayment::class] ?? [] as $payment) {
                if ($payment instanceof ReceivedDocumentPayment && $payment->document() === $loaded) {
                    $this->entityManager->detach($payment);
                }
            }
            foreach ($map[ReceivedDocumentLine::class] ?? [] as $line) {
                if ($line instanceof ReceivedDocumentLine && $line->document() === $loaded) {
                    $this->entityManager->detach($line);
                }
            }
            $this->entityManager->detach($loaded);
        }

        return $this->find($id) ?? throw new UnknownReceivedDocument();
    }

    public function add(ReceivedDocument $document): void
    {
        $this->entityManager->persist($document);
    }

    public function remove(ReceivedDocument $document): void
    {
        $this->entityManager->remove($document);
    }
}
