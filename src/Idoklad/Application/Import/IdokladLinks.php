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

    /** Stores the link with the import's unit of work. */
    public function add(IdokladLink $link): void;
}
