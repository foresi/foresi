<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine;

use App\Purchasing\Application\ReceivedDocuments;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentPayment;
use App\Shared\Domain\Id;
use Doctrine\ORM\EntityManagerInterface;

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

    public function lock(string $id): ReceivedDocument
    {
        if (!Id::isValid($id) || 1 !== $this->entityManager->getConnection()->executeStatement(self::LOCK, ['id' => $id])) {
            throw new UnknownReceivedDocument();
        }
        // What this process loaded before may be older than the lock, so it is read again.
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($id, ReceivedDocument::class);
        if ($loaded instanceof ReceivedDocument) {
            // Its payments loaded before would point at the detached copy, so they go too.
            foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[ReceivedDocumentPayment::class] ?? [] as $payment) {
                if ($payment instanceof ReceivedDocumentPayment && $payment->document() === $loaded) {
                    $this->entityManager->detach($payment);
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
