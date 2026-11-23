<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * Cash flow's pairings for the screens of a document module (ADR 0038): read how a document stands, list what a
 * person can pair it with, and pair or unpair it. Only the owners and members of the organisation use it.
 */
interface DocumentPairings
{
    /**
     * @return PairingView|null null when Cash flow has not looked at the document, or found no occurrence it may belong to
     *
     * @throws PairingRefused forbidden
     */
    public function view(FlowSubject $subject): ?PairingView;

    /**
     * The planned items of a direction with their days from $from to $to, both included.
     *
     * @return list<PairingChoice> items with no day in the period are left out
     *
     * @throws PairingRefused forbidden
     */
    public function choices(FlowDirection $direction, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * A person says the document belongs to this occurrence.
     *
     * @throws PairingRefused forbidden, not_found, direction_mismatch, not_an_occurrence
     */
    public function pair(FlowSubject $subject, string $plannedItemId, \DateTimeImmutable $occurrenceOn): void;

    /**
     * A person says the document belongs to no planned occurrence, so Cash flow leaves it unpaired.
     *
     * @throws PairingRefused forbidden, not_found
     */
    public function unpair(FlowSubject $subject): void;

    /**
     * The ids of the documents of a type that wait for a person to confirm a pairing.
     *
     * @return list<string>
     *
     * @throws PairingRefused forbidden
     */
    public function toConfirm(string $subjectType): array;
}
