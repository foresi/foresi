<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Domain\InboundAddress;

/**
 * The person's own inbound addresses, never their tokens.
 */
final readonly class AddressReads
{
    public function __construct(
        private InboundAddressStore $store,
        private InboundAddresses $addresses,
        private StaffCheck $staff,
        private AuditActor $actor,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @param string|null $purpose only the addresses of this purpose
     *
     * @return list<AddressView> oldest first, revoked ones too
     *
     * @throws MailRefused
     */
    public function mine(?string $purpose = null): array
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw MailRefused::forbidden();
        }
        $masked = $this->addresses->maskedTemplate();
        $today = $this->clock->today();

        return array_map(
            static fn (InboundAddress $address): AddressView => AddressView::of($address, $masked, $today),
            $this->store->ownedBy($userId, null === $purpose || '' === $purpose ? null : $purpose),
        );
    }
}
