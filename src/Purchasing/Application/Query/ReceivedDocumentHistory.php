<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\UserRepository;
use App\Purchasing\Domain\ReceivedDocument;

/**
 * The audit log of a received document as steps a person recognises: one
 * action is one step, as the use cases save it under one correlation. Recording
 * a draft, the trash and a restore are told as such; any other change names the
 * fields it changed. A save that changed nothing a person sees is left out.
 */
final readonly class ReceivedDocumentHistory
{
    /** The audited fields a person sees, by the last part of their translation key under purchasing.field. */
    private const array FIELDS = [
        'kind' => 'kind',
        'treatment' => 'treatment',
        'supplierParty' => 'supplier',
        'supplier' => 'supplier',
        'supplierNumber' => 'supplierNumber',
        'variableSymbol' => 'variableSymbol',
        'issueDate' => 'issueDate',
        'taxPointDate' => 'taxPointDate',
        'dueDate' => 'dueDate',
        'receivedDate' => 'receivedDate',
        'currency' => 'currency',
        'totalMinor' => 'total',
        'exchangeRate' => 'exchangeRate',
        'exchangeRateDate' => 'exchangeRate',
        'vatRecap' => 'vatRecap',
        'supplyCode' => 'supplyCode',
        'originalDocumentId' => 'originalDocument',
        'originalAttachmentId' => 'originalFile',
    ];

    public function __construct(private AuditTrail $trail, private UserRepository $users)
    {
    }

    /**
     * @return list<ReceivedDocumentHistoryEntry> oldest first
     */
    public function of(ReceivedDocument $document): array
    {
        $entries = $this->trail->rootHistory(ReceivedDocument::class, $document->id());
        $names = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (AuditEntry $entry): ?string => $entry->userId(), $entries), \is_string(...)))));
        $steps = [];
        foreach ($this->groups($entries) as $group) {
            $step = $this->step($group, $names);
            if ($step instanceof ReceivedDocumentHistoryEntry) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * The entries of one action together: the same correlation, or the same moment without one.
     *
     * @param list<AuditEntry> $entries
     *
     * @return list<non-empty-list<AuditEntry>>
     */
    private function groups(array $entries): array
    {
        $groups = [];
        $last = null;
        foreach ($entries as $entry) {
            $key = $entry->correlationId() ?? $entry->occurredAt()->format('U.u');
            if ($key === $last) {
                $groups[array_key_last($groups)][] = $entry;
                continue;
            }
            $groups[] = [$entry];
            $last = $key;
        }

        return $groups;
    }

    /**
     * @param non-empty-list<AuditEntry> $group
     * @param array<string, string>      $names by user id
     */
    private function step(array $group, array $names): ?ReceivedDocumentHistoryEntry
    {
        $userId = $group[0]->userId();
        $who = null === $userId ? null : ($names[$userId] ?? null);
        $at = $group[0]->occurredAt();
        $fields = [];
        $event = null;
        $reason = null;
        foreach ($group as $entry) {
            if (ReceivedDocument::class !== $entry->entityClass()) {
                continue;
            }
            if (AuditAction::Created === $entry->action()) {
                return new ReceivedDocumentHistoryEntry($at, $who, ReceivedDocumentHistoryEntry::CREATED);
            }
            $changes = $entry->changes();
            if (\array_key_exists('deletedAt', $changes)) {
                $trashed = null !== $changes['deletedAt'][1];
                $event = $trashed ? ReceivedDocumentHistoryEntry::TRASHED : ReceivedDocumentHistoryEntry::RESTORED;
                $reason = $trashed && \is_string($changes['deletedReason'][1] ?? null) ? $changes['deletedReason'][1] : null;
            }
            if ('recorded' === ($changes['state'][1] ?? null)) {
                $event = ReceivedDocumentHistoryEntry::RECORDED;
            }
            foreach (array_keys($changes) as $field) {
                $key = self::FIELDS[explode('.', $field)[0]] ?? null;
                if (null !== $key) {
                    $fields[] = $key;
                }
            }
        }
        if (null !== $event) {
            return new ReceivedDocumentHistoryEntry($at, $who, $event, ReceivedDocumentHistoryEntry::RECORDED === $event ? [] : array_values(array_unique($fields)), $reason);
        }

        return [] === $fields ? null : new ReceivedDocumentHistoryEntry($at, $who, ReceivedDocumentHistoryEntry::CHANGED, array_values(array_unique($fields)));
    }
}
