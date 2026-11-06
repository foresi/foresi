<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\UndoBlocker;
use App\Invoicing\Application\UndoImportedDocuments;
use App\Invoicing\Application\UnknownRecurringInvoice;
use App\Invoicing\Domain\RecurringInvoice;
use App\Party\Application\LegalEntities;
use Psr\Clock\ClockInterface;

/**
 * Takes back what an import run brought, for 30 days, in one transaction, all
 * or none: its documents and payments as Invoicing undoes them, its recurring
 * templates that nobody touched since, and the links of what went, so a later run brings
 * them again. A template edited, resumed or ended here stays, and so does its
 * link. Contacts and number series stay: other records may use them by now.
 */
final readonly class UndoIdokladImport
{
    /** Kept by an undo, with their links. */
    private const array KEPT = [IdokladResource::Contacts, IdokladResource::NumericSequences];

    public function __construct(
        private UndoImportedDocuments $documents,
        private IdokladLinks $links,
        private IdokladImportRuns $runs,
        private RecurringInvoices $templates,
        private LegalEntities $firms,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether the run brought something that may still be taken back.
     */
    public static function isUndoable(IdokladImportRun $run, \DateTimeImmutable $now): bool
    {
        return ImportMode::Run === $run->mode()
            && $run->finishedAt() instanceof \DateTimeImmutable
            && null === $run->error()
            && !$run->undoneAt() instanceof \DateTimeImmutable
            && $run->startedAt()->modify('+'.UndoImportedDocuments::DAYS.' days') >= $now;
    }

    /**
     * @return list<UndoBlocker> empty when the run was taken back; otherwise nothing changed
     *
     * @throws \DomainException for a run that cannot be undone
     */
    public function undo(IdokladImportRun $run): array
    {
        if (!self::isUndoable($run, $this->now())) {
            throw new \DomainException('Only a run of the last 30 days that brought something and was not undone can be undone.');
        }
        $runId = $run->id();
        $firmId = $run->firm()->id();

        // One transaction: a template that fails keeps the documents and payments too.
        return $this->documents->undo($runId, function () use ($runId, $firmId): array {
            foreach ($this->links->ofRun($this->firms->get($firmId), $runId) as $link) {
                if (\in_array($link->resource(), self::KEPT, true)) {
                    continue;
                }
                if (IdokladResource::RecurringInvoices === $link->resource()) {
                    try {
                        $template = $this->templates->get($link->targetId());
                    } catch (UnknownRecurringInvoice) {
                        $template = null;
                    }
                    if ($template instanceof RecurringInvoice && !$template->isUntouchedImport()) {
                        continue;
                    }
                    if ($template instanceof RecurringInvoice) {
                        $this->templates->remove($template);
                    }
                }
                $this->links->remove($link);
            }
            $this->runs->find($runId)?->markUndone($this->now());

            return [];
        });
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
