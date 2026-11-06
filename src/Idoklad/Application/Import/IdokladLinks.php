<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladLink;
use App\Idoklad\Domain\IdokladResource;
use App\Party\Domain\LegalEntity;

/** Port: what the iDoklad records of a firm became in Foresi. */
interface IdokladLinks
{
    public function find(LegalEntity $firm, IdokladResource $resource, int $externalId): ?IdokladLink;

    /**
     * The links one run made.
     *
     * @return list<IdokladLink>
     */
    public function ofRun(LegalEntity $firm, string $runId): array;

    /** Deletes the link; call it inside TransactionalWork. */
    public function remove(IdokladLink $link): void;

    /** Stores the link with the import's unit of work. */
    public function add(IdokladLink $link): void;
}
