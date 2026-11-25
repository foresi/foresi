<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Purchasing\Domain\CaptureAddress;
use Foresi\Purchasing\Domain\CaptureAddressRefused;
use Psr\Clock\ClockInterface;

/**
 * The address a person forwards received documents to, one per person: Mail
 * keeps the address and its secret, Purchasing the person's name for it. Every
 * action works on the signed-in person's own address and no other; the
 * address of somebody else is unknown. Never log an address.
 */
final readonly class CaptureAddresses
{
    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private CaptureAddressBook $book,
        private AddressIssuer $issuer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed  for anyone but the owners and members of the organisation
     * @throws CaptureAddressRefusal when the person has an address already, the name is unusable, or the server issues no address
     */
    public function create(string $label): IssuedCaptureAddress
    {
        $userId = $this->access->denyUnlessStaff();
        $label = $this->checked($label);

        return $this->unit->run(function () use ($userId, $label): IssuedCaptureAddress {
            if ($this->book->activeOf($userId) instanceof CaptureAddress) {
                throw CaptureAddressRefusal::exists();
            }
            try {
                $issued = $this->issuer->issue(ReceivedDocumentsPurpose::KEY, $userId, ReceivedDocumentsPurpose::LABEL);
            } catch (AddressNotIssued $unavailable) {
                throw CaptureAddressRefusal::unavailable($unavailable);
            }
            $address = new CaptureAddress($userId, $issued->id, $label, $this->now());
            $this->book->add($address);

            return new IssuedCaptureAddress($address->id(), $address->label(), $issued->address);
        });
    }

    /**
     * @throws PurchasingNotAllowed
     * @throws UnknownCaptureAddress
     * @throws CaptureAddressRefusal when the name is unusable
     */
    public function rename(string $id, string $label): void
    {
        $userId = $this->access->denyUnlessStaff();
        $label = $this->checked($label);

        $this->unit->run(function () use ($userId, $id, $label): void {
            $this->own($userId, $id)->rename($label);
        });
    }

    /**
     * A new address in place of the old one, which takes in nothing from now on.
     *
     * @throws PurchasingNotAllowed
     * @throws UnknownCaptureAddress
     * @throws CaptureAddressRefusal when the server issues no address
     */
    public function replace(string $id): IssuedCaptureAddress
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->unit->run(function () use ($userId, $id): IssuedCaptureAddress {
            $address = $this->own($userId, $id);
            try {
                $issued = $this->issuer->rotate($address->mailAddressId());
            } catch (AddressNotIssued $unavailable) {
                throw CaptureAddressRefusal::unavailable($unavailable);
            }

            return new IssuedCaptureAddress($address->id(), $address->label(), $issued->address);
        });
    }

    /**
     * @throws PurchasingNotAllowed
     * @throws UnknownCaptureAddress
     */
    public function revoke(string $id): void
    {
        $userId = $this->access->denyUnlessStaff();

        $this->unit->run(function () use ($userId, $id): void {
            $address = $this->own($userId, $id);
            $this->issuer->revoke($address->mailAddressId());
            $address->revoke($this->now());
        });
    }

    /**
     * The address again, for the forwarding instructions.
     *
     * @throws PurchasingNotAllowed
     * @throws UnknownCaptureAddress
     * @throws CaptureAddressRefusal when the server cannot open it
     */
    public function reveal(string $id): ?string
    {
        $userId = $this->access->denyUnlessStaff();
        $address = $this->own($userId, $id);
        try {
            return $this->issuer->reveal($address->mailAddressId());
        } catch (AddressNotIssued $unavailable) {
            throw CaptureAddressRefusal::unavailable($unavailable);
        }
    }

    /**
     * The signed-in person's address with its address shown; null when they have none.
     *
     * @throws PurchasingNotAllowed
     */
    public function mine(): ?CaptureAddressView
    {
        $userId = $this->access->denyUnlessStaff();
        $address = $this->book->activeOf($userId);
        if (!$address instanceof CaptureAddress) {
            return null;
        }
        try {
            $shown = $this->issuer->reveal($address->mailAddressId());
        } catch (AddressNotIssued) {
            $shown = null;
        }

        return new CaptureAddressView($address->id(), $address->label(), $shown, $address->createdAt(), $address->lastReceivedAt());
    }

    private function own(string $userId, string $id): CaptureAddress
    {
        $address = $this->book->activeOf($userId);
        if (!$address instanceof CaptureAddress || $address->id() !== $id) {
            throw new UnknownCaptureAddress();
        }

        return $address;
    }

    private function checked(string $label): string
    {
        try {
            return CaptureAddress::checkedLabel($label);
        } catch (CaptureAddressRefused $refused) {
            throw CaptureAddressRefusal::name($refused);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
