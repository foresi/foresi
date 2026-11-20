<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application\Import;

use Foresi\Idoklad\Domain\IdokladLink;
use Foresi\Idoklad\Domain\IdokladResource;

/** Port: what the iDoklad records became in Foresi. */
interface IdokladLinks
{
    public function find(IdokladResource $resource, int $externalId): ?IdokladLink;

    /**
     * The links one run made.
     *
     * @return list<IdokladLink>
     */
    public function ofRun(string $runId): array;

    /** Deletes the link; call it inside TransactionalWork. */
    public function remove(IdokladLink $link): void;

    /** Stores the link with the import's unit of work. */
    public function add(IdokladLink $link): void;
}
