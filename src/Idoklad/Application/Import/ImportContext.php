<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportOutcome;
use App\Idoklad\Domain\ImportReport;
use App\Party\Domain\LegalEntity;

/**
 * What a stage works with: the firm and its snapshot, the links of this and
 * earlier runs, and the report. In a check, stages write as in a run (the
 * transaction is rolled back), but never files: ask writesFiles().
 */
final readonly class ImportContext
{
    public function __construct(
        public LegalEntity $firm,
        public string $runId,
        public ImportMode $mode,
        private ImportReport $report,
        private IdokladSnapshot $snapshot,
        private IdokladLinks $links,
        private \DateTimeImmutable $now,
    ) {
    }

    /**
     * @return iterable<IdokladRecord>
     */
    public function records(IdokladResource $resource): iterable
    {
        return $this->snapshot->records($this->firm, $resource);
    }

    public function record(IdokladResource $resource, int $externalId): ?IdokladRecord
    {
        return $this->snapshot->record($this->firm, $resource, $externalId);
    }

    /**
     * The id of the Foresi record an iDoklad record became, if a run linked it.
     */
    public function linkedTo(IdokladResource $resource, int $externalId): ?string
    {
        return $this->links->find($this->firm, $resource, $externalId)?->targetId();
    }

    public function link(IdokladResource $resource, int $externalId, string $targetId): void
    {
        $this->links->add(new IdokladLink($this->firm, $resource, $externalId, $targetId, $this->runId, $this->now));
    }

    /**
     * @param list<string>                        $reasons translation keys
     * @param array<string, int|string|bool|null> $values
     */
    public function report(string $stage, int $externalId, ImportOutcome $outcome, ?string $targetId = null, array $reasons = [], array $values = []): void
    {
        $this->report->add($stage, $externalId, $outcome, $targetId, $reasons, $values);
    }

    public function writesFiles(): bool
    {
        return $this->mode->writes();
    }
}
