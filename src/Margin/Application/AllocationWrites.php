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
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\SupplierDefault;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What people decide about who bears a cost: the mapping of one line, the
 * assignment of a whole document, the mapping of a planned item and the
 * default of a supplier, the last two from a month on. A client must be an
 * existing contact that is not archived. Each write replaces the shares it
 * changes at once, in the same unit of work, and never those of a month
 * before the one it applies from. Only the owners and members of the
 * organisation decide.
 */
final readonly class AllocationWrites
{
    private const string AMOUNT = '/^[0-9]{1,13}(\.[0-9]{1,2})?\z/';
    private const string MONTH = '/^[0-9]{4}-(0[1-9]|1[0-2])\z/';
    /** A month before any document, so documentsOf lists them all. */
    private const string EVERY_MONTH = '1900-01-01';

    public function __construct(
        private MarginAccess $access,
        private CostSources $sources,
        private CostShareCalculation $calculation,
        private AllocationReads $reads,
        private EntityManagerInterface $entityManager,
        private PartyDirectory $contacts,
        private AuditActor $actor,
        private ClockInterface $clock,
        private TransactionalWork $transaction,
        private CostPairings $pairings,
    ) {
    }

    /**
     * Maps the costs paired with a planned item from a month on, or ends the
     * mapping from that month with null. Setting a month that has a version
     * replaces it. Recalculates the documents paired with occurrences in that
     * month or later.
     *
     * @param string $fromMonth YYYY-MM
     *
     * @throws MarginRefused forbidden, invalid_month, unknown_planned_item, not_an_expense, not_recurring, counterparty_required, unknown_contact
     */
    public function setPlanned(string $plannedItemId, ?Allocation $allocation, string $fromMonth): PlannedAllocationsView
    {
        $this->access->denyUnlessStaff();
        $month = $this->month($fromMonth);
        $item = $this->pairings->item($plannedItemId) ?? throw AllocationReads::unknownPlannedItem();
        if ($allocation instanceof Allocation) {
            if (!$item->expense) {
                throw MarginRefused::invalid(MarginRefused::NOT_AN_EXPENSE, 'plannedItemId', 'Only a planned cost, money going out, is mapped to clients.');
            }
            if (!$item->recurring) {
                throw MarginRefused::invalid(MarginRefused::NOT_RECURRING, 'plannedItemId', 'Only a recurring planned cost is mapped; map the document of a one-off cost itself.');
            }
            if (null === $item->counterpartyId) {
                throw MarginRefused::invalid(MarginRefused::COUNTERPARTY_REQUIRED, 'plannedItemId', 'The planned item has no counterparty, so no document is paired with it. Give it the supplier first.');
            }
            foreach ($allocation->shares as $share) {
                $this->knownContact($share->recipient, 'shares');
            }
        }
        $stored = $this->entityManager->getRepository(PlannedAllocation::class)->findOneBy(['plannedItemId' => $item->id, 'fromMonth' => $month]);

        return $this->transaction->run(function () use ($item, $allocation, $month, $stored): PlannedAllocationsView {
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $by = (string) $this->actor->userId();
            if ($stored instanceof PlannedAllocation) {
                $stored->replace($allocation, $now, $by);
            } else {
                $this->entityManager->persist(new PlannedAllocation(Uuid::v7()->toRfc4122(), $item->id, $month, $allocation, $now, $by));
            }
            $this->entityManager->flush();
            $this->calculation->recalculateAll($this->pairings->documentsOfItemFrom($item->id, $month));
            $this->entityManager->flush();

            return $this->reads->planned($item->id);
        });
    }

    /**
     * setPlanned for what a client sent: the allocation as an object of kind and shares.
     *
     * @throws MarginRefused what setPlanned refuses, kind_invalid, share_invalid, recipient_invalid, amount_invalid or what an allocation refuses
     */
    public function setPlannedAllocation(SetPlannedAllocationInput $input): PlannedAllocationsView
    {
        $this->access->denyUnlessStaff();
        $allocation = null;
        if (null !== $input->allocation) {
            $kind = $input->allocation['kind'] ?? null;
            $shares = $input->allocation['shares'] ?? [];
            if (!\is_string($kind)) {
                throw MarginRefused::invalid(MarginRefused::KIND_INVALID, 'allocation', 'The allocation has a kind: whole, percent or fixed.');
            }
            if (!\is_array($shares) || !array_is_list($shares)) {
                throw MarginRefused::invalid(MarginRefused::SHARE_INVALID, 'allocation', 'The shares are a list of objects that name their recipient.');
            }
            $allocation = $this->allocation($kind, $shares);
        }

        return $this->setPlanned($input->plannedItemId, $allocation, $input->fromMonth);
    }

    /**
     * setSupplierDefault for what a client sent: the recipient as text.
     *
     * @throws MarginRefused what setSupplierDefault refuses, or recipient_invalid
     */
    public function setSupplierDefaultRecipient(SetSupplierDefaultInput $input): SupplierDefaultsView
    {
        $this->access->denyUnlessStaff();
        $recipient = null === $input->recipient ? null : $this->recipient($input->recipient, 'recipient');

        return $this->setSupplierDefault($input->contactId, $recipient, $input->fromMonth);
    }

    /**
     * Gives the costs of a supplier to one recipient by default from a month
     * on, or ends the default from that month with null. Setting a month that
     * has a version replaces it.
     *
     * @param string $fromMonth YYYY-MM
     *
     * @throws MarginRefused forbidden, invalid_month, unknown_supplier, unknown_contact
     */
    public function setSupplierDefault(string $contactId, ?Recipient $recipient, string $fromMonth): SupplierDefaultsView
    {
        $this->access->denyUnlessStaff();
        $month = $this->month($fromMonth);
        $supplier = AllocationReads::isUuid($contactId) ? $this->contacts->find($contactId) : null;
        if (!$supplier instanceof \Foresi\Party\Contract\PartySummary || $supplier->archived) {
            throw AllocationReads::unknownSupplier();
        }
        if ($recipient instanceof Recipient) {
            $this->knownContact($recipient, 'recipient');
        }
        $stored = $this->entityManager->getRepository(SupplierDefault::class)->findOneBy(['supplierId' => $supplier->id, 'fromMonth' => $month]);

        return $this->transaction->run(function () use ($supplier, $recipient, $month, $stored): SupplierDefaultsView {
            if ($stored instanceof SupplierDefault) {
                $stored->replace($recipient);
            } else {
                $this->entityManager->persist(new SupplierDefault(Uuid::v7()->toRfc4122(), $supplier->id, $month, $recipient));
            }
            $this->entityManager->flush();
            // The cost month of a paired document is that of its occurrence, which may be months after its
            // month basis (a quarterly or yearly item), so no lower bound is right: every document of the
            // supplier is listed, and only those whose cost month is $month or later are counted again.
            // Earlier months are not touched. One pairing lookup per document of the supplier.
            $documents = [];
            foreach ($this->sources->documentsOf($supplier->id, new \DateTimeImmutable(self::EVERY_MONTH)) as $id) {
                $costMonth = $this->calculation->costMonthOf($id);
                if ($costMonth instanceof \DateTimeImmutable && $costMonth >= $month) {
                    $documents[] = $id;
                }
            }
            $this->calculation->recalculateAll($documents);
            $this->entityManager->flush();

            return $this->reads->supplierDefault($supplier->id);
        });
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

    /**
     * The first day of a month written YYYY-MM.
     */
    private function month(string $value): \DateTimeImmutable
    {
        if (1 !== preg_match(self::MONTH, $value)) {
            throw MarginRefused::invalid(MarginRefused::INVALID_MONTH, 'fromMonth', 'A month is written YYYY-MM, such as "2026-12".');
        }

        $month = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if (false === $month) {
            throw new \LogicException('A checked month does not parse.');
        }

        return $month;
    }

    private function recipient(string $value, string $field): Recipient
    {
        try {
            $recipient = Recipient::fromString($value);
        } catch (\InvalidArgumentException) {
            throw MarginRefused::invalid(MarginRefused::RECIPIENT_INVALID, $field, 'A recipient is "own" or the id of a contact.');
        }

        return $this->knownContact($recipient, $field);
    }

    private function knownContact(Recipient $recipient, string $field): Recipient
    {
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
