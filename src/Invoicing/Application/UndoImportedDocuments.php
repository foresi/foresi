<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Party\Application\TransactionalWork;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Takes back the documents one import brought, all of them or none: for 30 days,
 * and only while nothing done here since stands on any of them. Any change of a
 * document counts, as the version of an imported document stays that of the import
 * until one; so does an export for the accountant from here, or a payment, a send
 * or a document that corrects, taxes or deducts it.
 *
 * The documents are deleted for good, delivered ones too, which the trash never
 * takes: they were not issued here, so nothing of the firm's numbering is given up.
 * The audit log keeps every deleted row. The counter a typed number advanced stays.
 */
final readonly class UndoImportedDocuments
{
    public const int DAYS = 30;

    public function __construct(
        private ImportedDocuments $imported,
        private OriginalDocuments $originals,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
        private TenantContext $tenants,
    ) {
    }

    /**
     * @return list<UndoBlocker> empty when the run was taken back; otherwise nothing changed
     */
    public function undo(string $runId): array
    {
        $removed = [];
        $blockers = $this->retry->run(function () use ($runId, &$removed): array {
            return $this->work->run(function () use ($runId, &$removed): array {
                $removed = [];
                $invoices = $this->imported->ofRun($runId);
                $blockers = array_merge(...array_map($this->blockersOf(...), $invoices));
                if ([] !== $blockers) {
                    return $blockers;
                }
                foreach ($invoices as $invoice) {
                    $removed[] = $invoice->id();
                    $this->imported->remove($invoice);
                }

                return [];
            });
        });
        // The files go once the rows are gone for good.
        $tenant = $this->tenants->current()->toString();
        foreach ($removed as $id) {
            $this->originals->remove($tenant, $id);
        }

        return $blockers;
    }

    /**
     * @return list<UndoBlocker>
     */
    private function blockersOf(Invoice $invoice): array
    {
        $importedAt = $invoice->importedAt() ?? throw new \LogicException('A run holds imported documents only.');
        $reasons = $this->imported->dependents($invoice);
        if ($importedAt->modify('+'.self::DAYS.' days') < $this->clock->now()) {
            $reasons[] = UndoBlocker::EXPIRED;
        }
        if (1 !== $invoice->version()) {
            $reasons[] = UndoBlocker::CHANGED;
        }
        if ($invoice->exportedAt() instanceof \DateTimeImmutable && $invoice->exportedAt() > $importedAt) {
            $reasons[] = UndoBlocker::EXPORTED;
        }

        return array_map(static fn (string $reason): UndoBlocker => new UndoBlocker($invoice->id(), $invoice->number(), $reason), $reasons);
    }
}
