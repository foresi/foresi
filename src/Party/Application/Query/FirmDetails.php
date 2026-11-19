<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\Query;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Application\PartyAccess;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Domain\LegalEntity;

/**
 * The one firm of the instance (ADR 0039) for the API and MCP: the company that
 * issues its documents and receives its payments. Only the owners and members
 * of the organisation read it.
 */
final readonly class FirmDetails
{
    public function __construct(private PartyAccess $access, private InstanceFirm $firm)
    {
    }

    /**
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     * @throws NoFirmYet       before the first-run setup
     */
    public function get(): LegalEntity
    {
        $this->access->denyUnlessStaff();

        return $this->firm->get();
    }
}
