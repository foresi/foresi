<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\UserRepository;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;

/**
 * The audit log of an invoice as steps a person recognises. One save writes an entry
 * for the document and one for each line it touched; those belong together when the
 * same person wrote them within one second. A line is named by its place and its text
 * at that moment, which the log itself holds, so a removed line keeps its name.
 */
final readonly class InvoiceHistory
{
    /** Derived from other fields, or moved into an event of its own. */
    private const array QUIET_INVOICE_FIELDS = ['numberKey', 'netMinor', 'vatMinor', 'roundingMinor', 'customerParty', 'deliveredAt', 'deletedAt', 'deletedBy', 'deletedReason'];
    private const array QUIET_LINE_FIELDS = ['invoice', 'position', 'rate', 'unitNetMinor', 'netMinor', 'vatMinor', 'grossMinor'];

    public function __construct(private AuditTrail $trail, private UserRepository $users)
    {
    }

    /**
     * @return list<InvoiceHistoryEntry> oldest first
     */
    public function of(Invoice $invoice): array
    {
        $entries = array_values(array_filter(
            $this->trail->rootHistory(Invoice::class, $invoice->id()),
            static fn (AuditEntry $entry): bool => \in_array($entry->entityClass(), [Invoice::class, InvoiceLine::class], true),
        ));
        $names = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (AuditEntry $entry): ?string => $entry->userId(), $entries), \is_string(...)))));

        $steps = [];
        $lines = [];
        foreach ($this->grouped($entries) as $group) {
            $steps[] = $this->step($group, $names, $lines);
        }

        return $steps;
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<non-empty-list<AuditEntry>>
     */
    private function grouped(array $entries): array
    {
        $groups = [];
        $previous = null;
        foreach ($entries as $entry) {
            $key = $entry->userId().'@'.$entry->occurredAt()->format('U');
            if ($key === $previous) {
                $groups[array_key_last($groups)][] = $entry;
            } else {
                $groups[] = [$entry];
            }
            $previous = $key;
        }

        return $groups;
    }

    /**
     * @param non-empty-list<AuditEntry>        $group
     * @param array<string, string>             $names by user id
     * @param array<string, array{int, string}> $lines place and text by line id, as the log has gone so far
     */
    private function step(array $group, array $names, array &$lines): InvoiceHistoryEntry
    {
        $fields = [];
        $reason = null;
        $event = InvoiceHistoryEntry::CHANGED;
        $shown = [];
        foreach ($group as $entry) {
            $changes = $entry->changes();
            if (InvoiceLine::class === $entry->entityClass()) {
                $shown[] = $this->line($entry, $lines);
                continue;
            }
            $event = $this->event($entry) ?? $event;
            $reason = isset($changes['deletedReason'][1]) && \is_string($changes['deletedReason'][1]) ? $changes['deletedReason'][1] : $reason;
            $fields = [...$fields, ...$this->fields($changes, self::QUIET_INVOICE_FIELDS)];
        }
        $userId = $group[0]->userId();
        $created = InvoiceHistoryEntry::CREATED === $event;

        return new InvoiceHistoryEntry(
            $group[0]->occurredAt(),
            null === $userId ? null : ($names[$userId] ?? null),
            $event,
            $created ? [] : array_values(array_unique($fields)),
            $created ? [] : array_values(array_filter($shown, static fn (InvoiceHistoryLine $line): bool => InvoiceHistoryLine::CHANGED !== $line->change || [] !== $line->fields)),
            $reason,
        );
    }

    private function event(AuditEntry $entry): ?string
    {
        $changes = $entry->changes();
        if (AuditAction::Created === $entry->action()) {
            return InvoiceHistoryEntry::CREATED;
        }
        if (isset($changes['deletedAt'])) {
            return null === $changes['deletedAt'][1] ? InvoiceHistoryEntry::RESTORED : InvoiceHistoryEntry::TRASHED;
        }

        return isset($changes['deliveredAt']) ? InvoiceHistoryEntry::DELIVERED : null;
    }

    /**
     * @param array<string, array{int, string}> $lines
     */
    private function line(AuditEntry $entry, array &$lines): InvoiceHistoryLine
    {
        $changes = $entry->changes();
        $id = $entry->entityId();
        $removed = AuditAction::Deleted === $entry->action();
        [$place, $text] = $lines[$id] ?? [0, ''];
        $position = $changes['position'][$removed ? 0 : 1] ?? $place;
        $newText = $changes['text'][$removed ? 0 : 1] ?? $text;
        $place = \is_int($position) ? $position : $place;
        $text = \is_string($newText) ? $newText : $text;
        $lines[$id] = [$place, $text];

        return new InvoiceHistoryLine(
            $place + 1,
            $text,
            match ($entry->action()) {
                AuditAction::Created => InvoiceHistoryLine::ADDED,
                AuditAction::Deleted => InvoiceHistoryLine::REMOVED,
                default => InvoiceHistoryLine::CHANGED,
            },
            $this->fields($changes, self::QUIET_LINE_FIELDS),
        );
    }

    /**
     * What sits inside a copy of a party is reported as that party.
     *
     * @param array<string, array<mixed>> $changes
     * @param list<string>                $quiet
     *
     * @return list<string>
     */
    private function fields(array $changes, array $quiet): array
    {
        $fields = array_map(static fn (string $field): string => explode('.', $field)[0], array_keys($changes));

        return array_values(array_unique(array_diff($fields, $quiet)));
    }
}
