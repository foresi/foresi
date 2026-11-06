<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Party\Domain\LegalEntity;

/**
 * Port: the records copied from a firm's iDoklad account, and the pulls that
 * copied them. What a later import step reads.
 */
interface IdokladSnapshot
{
    public function record(LegalEntity $firm, IdokladResource $resource, int $externalId): ?IdokladRecord;

    /**
     * The records of one list, by their iDoklad id.
     *
     * @return iterable<IdokladRecord>
     */
    public function records(LegalEntity $firm, IdokladResource $resource): iterable;

    /**
     * Records of documents that have a PDF report and no stored PDF yet.
     *
     * @return list<IdokladRecord>
     */
    public function withoutPdf(LegalEntity $firm, int $limit): array;

    /**
     * The latest pull that finished without an error; a delta pull starts from its latest change.
     */
    public function lastSucceededPull(LegalEntity $firm): ?IdokladPull;

    public function add(IdokladRecord|IdokladPull $entity): void;
}
