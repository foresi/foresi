<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Query;

use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\PartyAccess;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Domain\LegalEntity;

/**
 * The firms of the organisation for MCP, so a client can name the firm a read is
 * narrowed to. Only the owners and members of the organisation read them.
 */
final readonly class FirmList
{
    public function __construct(private PartyAccess $access, private LegalEntities $firms)
    {
    }

    /**
     * Active and archived, by name.
     *
     * @return list<FirmRow>
     *
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     */
    public function all(): array
    {
        $this->access->denyUnlessStaff();

        return array_map(static fn (LegalEntity $firm): FirmRow => new FirmRow($firm->id(), $firm->name(), $firm->isArchived()), $this->firms->all());
    }
}
