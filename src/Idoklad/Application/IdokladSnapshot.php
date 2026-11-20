<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application;

use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladRecord;
use Foresi\Idoklad\Domain\IdokladResource;

/**
 * Port: the records copied from the iDoklad account, and the pulls that
 * copied them. What a later import step reads.
 */
interface IdokladSnapshot
{
    public function record(IdokladResource $resource, int $externalId): ?IdokladRecord;

    /**
     * The records of one list, by their iDoklad id.
     *
     * @return iterable<IdokladRecord>
     */
    public function records(IdokladResource $resource): iterable;

    /**
     * Records of documents that have a PDF report and no stored PDF yet.
     *
     * @return list<IdokladRecord>
     */
    public function withoutPdf(int $limit): array;

    /**
     * The latest pull that finished without an error; a delta pull starts from its latest change.
     */
    public function lastSucceededPull(): ?IdokladPull;

    /**
     * The pulls, the latest first.
     *
     * @return list<IdokladPull>
     */
    public function pulls(): array;

    public function add(IdokladRecord|IdokladPull $entity): void;
}
