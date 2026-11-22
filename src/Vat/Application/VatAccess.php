<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Domain\LegalEntity;

/**
 * Who works with the VAT: the owners and the members of the organisation,
 * never its guests, on screens and with a token alike, since the VAT is the
 * firm's and not a person's. Every use case asks here first, and finds the
 * firm of the instance here.
 */
final readonly class VatAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor, private InstanceFirm $firm)
    {
    }

    /**
     * @throws VatRefused forbidden
     */
    public function denyUnlessStaff(): void
    {
        if (null === $this->actor->userId() || !$this->staff->isStaff()) {
            throw VatRefused::forbidden();
        }
    }

    /**
     * The one firm of the instance (ADR 0039).
     *
     * @throws VatRefused not_found before the first-run setup
     */
    public function firm(): LegalEntity
    {
        try {
            return $this->firm->get();
        } catch (NoFirmYet) {
            throw VatRefused::notFound('The instance has no firm yet: its first-run setup has not happened.');
        }
    }
}
