<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Audit\Application\AuditActor;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Contract\CostSharesRecalculation;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\Recipient;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What people decide about who bears a cost: the mapping of one line, and the
 * assignment of a whole document. A client must be an existing contact that is
 * not archived. Each write replaces the shares of the document at once, in the
 * same unit of work. Only the owners and members of the organisation decide.
 */
final readonly class AllocationWrites
{
    private const string AMOUNT = '/^[0-9]{1,13}(\.[0-9]{1,2})?\z/';

    public function __construct(
        private MarginAccess $access,
        private CostSources $sources,
        private CostSharesRecalculation $calculation,
        private AllocationReads $reads,
        private EntityManagerInterface $entityManager,
        private PartyDirectory $contacts,
        private AuditActor $actor,
        private ClockInterface $clock,
        private TransactionalWork $transaction,
    ) {
    }

    /**
     * @throws MarginRefused forbidden, unknown_document, unknown_line, unknown_contact, an unreadable value, or what an allocation refuses
     */
    public function setLine(SetLineAllocationInput $input): DocumentAllocationView
    {
        $this->access->denyUnlessStaff();
        $document = $this->sources->document($input->documentId) ?? throw AllocationReads::unknownDocument();
        $stored = $this->entityManager->getRepository(LineAllocation::class)->findOneBy(['documentId' => $document->id, 'lineKey' => $input->lineKey]);
        $allocation = null === $input->kind ? null : $this->allocation($input->kind, $input->shares ?? []);
        if (!array_any($document->lines, static fn (CostLine $line): bool => $line->key === $input->lineKey) && (!$stored instanceof LineAllocation || $allocation instanceof Allocation)) {
            throw MarginRefused::notFound(MarginRefused::UNKNOWN_LINE, 'The document has no line with this key. Read the keys of its lines with foresi_get_document_allocation.');
        }

        return $this->transaction->run(function () use ($document, $input, $stored, $allocation): DocumentAllocationView {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $by = (string) $this->actor->userId();
            if (!$allocation instanceof Allocation) {
                if ($stored instanceof LineAllocation) {
                    $this->entityManager->remove($stored);
                }
            } elseif ($stored instanceof LineAllocation) {
                $stored->change($allocation, $now, $by);
            } else {
                $this->entityManager->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $document->id, $input->lineKey, $allocation, $now, $by));
            }

            return $this->recalculated($document);
        });
    }

    /**
     * @throws MarginRefused forbidden, unknown_document, unknown_contact, recipient_invalid
     */
    public function assign(AssignDocumentInput $input): DocumentAllocationView
    {
        $this->access->denyUnlessStaff();
        $document = $this->sources->document($input->documentId) ?? throw AllocationReads::unknownDocument();
        $recipient = null === $input->recipient ? null : $this->recipient($input->recipient, 'recipient');
        $stored = $this->entityManager->getRepository(DocumentAllocation::class)->findOneBy(['documentId' => $document->id]);

        return $this->transaction->run(function () use ($document, $recipient, $stored): DocumentAllocationView {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $by = (string) $this->actor->userId();
            if (!$recipient instanceof Recipient) {
                if ($stored instanceof DocumentAllocation) {
                    $this->entityManager->remove($stored);
                }
            } elseif ($stored instanceof DocumentAllocation) {
                $stored->change($recipient, $now, $by);
            } else {
                $this->entityManager->persist(new DocumentAllocation(Uuid::v7()->toRfc4122(), $document->id, $recipient, $now, $by));
            }

            return $this->recalculated($document);
        });
    }

    private function recalculated(CostDocument $document): DocumentAllocationView
    {
        // The calculation reads the mappings from the database, so they are stored first.
        $this->entityManager->flush();
        $this->calculation->recalculate($document->id);
        $this->entityManager->flush();

        return $this->reads->view($document);
    }

    /**
     * @param list<mixed> $shares
     */
    private function allocation(string $kind, array $shares): Allocation
    {
        $kind = AllocationKind::tryFrom($kind) ?? throw MarginRefused::invalid(MarginRefused::KIND_INVALID, 'kind', 'The kind is whole, percent or fixed.');
        $built = [];
        foreach ($shares as $share) {
            $built[] = $this->share($share);
        }

        return new Allocation($kind, $built);
    }

    private function share(mixed $share): AllocationShare
    {
        if (!\is_array($share) || !\is_string($share['recipient'] ?? null)) {
            throw MarginRefused::invalid(MarginRefused::SHARE_INVALID, 'shares', 'Each share is an object that names its recipient, and has a percent, an amount or rest.');
        }
        $percent = $share['percent'] ?? null;
        $amount = $share['amount'] ?? null;
        $rest = $share['rest'] ?? false;
        if (\is_int($percent)) {
            $percent = (string) $percent;
        }
        if ((null !== $percent && !\is_string($percent)) || (null !== $amount && !\is_string($amount)) || !\is_bool($rest)) {
            throw MarginRefused::invalid(MarginRefused::SHARE_INVALID, 'shares', 'A percent and an amount are decimals as text, such as "33.33" and "800.00"; rest is true or false.');
        }
        if (null !== $amount && 1 !== preg_match(self::AMOUNT, trim($amount))) {
            throw MarginRefused::invalid(MarginRefused::AMOUNT_INVALID, 'shares', 'An amount is a decimal number in CZK with at most two decimals, such as "800.00".');
        }

        return new AllocationShare(
            $this->recipient($share['recipient'], 'shares'),
            $percent,
            null === $amount ? null : Money::of(trim($amount), Currency::CZK),
            $rest,
        );
    }

    private function recipient(string $value, string $field): Recipient
    {
        try {
            $recipient = Recipient::fromString($value);
        } catch (\InvalidArgumentException) {
            throw MarginRefused::invalid(MarginRefused::RECIPIENT_INVALID, $field, 'A recipient is "own" or the id of a contact.');
        }
        $contactId = $recipient->contactId();
        if (null !== $contactId) {
            $contact = $this->contacts->find($contactId);
            if (!$contact instanceof \Foresi\Party\Contract\PartySummary || $contact->archived) {
                throw MarginRefused::invalid(MarginRefused::UNKNOWN_CONTACT, $field, 'The organisation has no active contact with this id. Find the client with foresi_search_contacts, or create it with foresi_create_contact.');
            }
        }

        return $recipient;
    }
}
