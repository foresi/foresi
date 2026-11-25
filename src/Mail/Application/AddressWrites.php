<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Domain\InboundAddress;
use Psr\Clock\ClockInterface;

/**
 * A person creates, renames, revokes and replaces their own inbound addresses;
 * the staff only (ADR 0041). Someone else's address is as unknown as one that
 * does not exist, and so is one of a purpose that has no owners, such as a bank
 * feed, which Banking manages. A person has one address per purpose that is not
 * revoked. Never log an address.
 */
final readonly class AddressWrites
{
    public function __construct(
        private InboundAddressStore $store,
        private InboundPurposes $purposes,
        private InboundAddresses $addresses,
        private AddressIssuer $issuer,
        private MailTransaction $transaction,
        private StaffCheck $staff,
        private AuditActor $actor,
        private ClockInterface $clock,
        private InstanceClock $instance,
    ) {
    }

    /**
     * @throws MailRefused
     */
    public function create(CreateAddressInput $input): IssuedMailAddress
    {
        $userId = $this->me();
        $purpose = $this->ownedPurpose($input->purpose);
        $label = $this->label($input->label);

        return $this->issue($userId, $purpose, $label, null);
    }

    /**
     * @throws MailRefused
     */
    public function rename(RenameAddressInput $input): AddressView
    {
        $address = $this->mine($input->addressId);
        $label = $this->label($input->label);

        $this->transaction->run(static function () use ($address, $label): void {
            $address->rename($label);
        });

        return $this->view($address);
    }

    /**
     * @throws MailRefused
     */
    public function revoke(RevokeAddressInput $input): AddressView
    {
        $address = $this->mine($input->addressId);
        $this->refuseWhenRevoked($address);

        $this->transaction->run(function () use ($address): void {
            $address->revoke($this->now());
        });

        return $this->view($address);
    }

    /**
     * The old address is revoked and a new one issued in one transaction: when
     * the new one cannot be issued, the old one stays as it was.
     *
     * @throws MailRefused
     */
    public function replace(ReplaceAddressInput $input): IssuedMailAddress
    {
        $userId = $this->me();
        $address = $this->mine($input->addressId);
        $this->refuseWhenRevoked($address);
        $purpose = $this->ownedPurpose($address->purpose());

        return $this->issue($userId, $purpose, $address->label(), $address);
    }

    /**
     * @param InboundAddress|null $replaced revoked first, in the same transaction
     *
     * @throws MailRefused
     */
    private function issue(string $userId, InboundPurpose $purpose, string $label, ?InboundAddress $replaced): IssuedMailAddress
    {
        $unavailable = $this->issuer->unavailable($purpose->key());
        if ($unavailable instanceof AddressUnavailable) {
            throw $this->unavailable($unavailable);
        }
        if (!$replaced instanceof InboundAddress && $this->store->activeOf($userId, $purpose->key()) instanceof InboundAddress) {
            throw MailRefused::conflict('address_exists', 'You already have an inbound address for this purpose. Replace it with foresi_replace_mail_address, or revoke it first.');
        }

        try {
            $issued = $this->transaction->run(function () use ($userId, $purpose, $label, $replaced): array {
                if ($replaced instanceof InboundAddress) {
                    $replaced->revoke($this->now());
                    $this->store->flush();
                }
                $issued = $this->issuer->issue($purpose->key(), $userId, $label);
                // Written now, so a second request at once is met here, by the index.
                $this->store->flush();
                $created = $this->store->get($issued->id) ?? throw new \LogicException('The address just issued is missing.');

                return [$created, $issued->address];
            });
        } catch (AddressNotIssued $refused) {
            throw $this->unavailable($refused->reason);
        } catch (AddressAlreadyActive) {
            // Two requests at once; the partial unique index let one of them in.
            if ($replaced instanceof InboundAddress) {
                // The other request replaced this address first.
                throw MailRefused::conflict('address_revoked', 'This address is revoked already. Create a new one with foresi_create_mail_address.');
            }

            throw MailRefused::conflict('address_exists', 'You already have an inbound address for this purpose. Replace it with foresi_replace_mail_address, or revoke it first.');
        }
        [$created, $address] = $issued;

        return new IssuedMailAddress($this->view($created), $address);
    }

    /**
     * @throws MailRefused
     */
    private function mine(string $id): InboundAddress
    {
        $userId = $this->me();
        $address = $this->store->get($id);
        if (!$address instanceof InboundAddress || $address->ownerUserId() !== $userId || true !== $this->purposes->get($address->purpose())?->ownerRequired()) {
            throw MailRefused::notFound();
        }

        return $address;
    }

    /**
     * @throws MailRefused
     */
    private function refuseWhenRevoked(InboundAddress $address): void
    {
        if ($address->isRevoked()) {
            throw MailRefused::conflict('address_revoked', 'This address is revoked already. Create a new one with foresi_create_mail_address.');
        }
    }

    /**
     * @throws MailRefused
     */
    private function ownedPurpose(string $key): InboundPurpose
    {
        $purpose = $this->purposes->get(trim($key));
        if (!$purpose instanceof InboundPurpose) {
            throw MailRefused::invalid('purpose_unknown', 'purpose', 'No installed module takes mail for this purpose.');
        }
        if (!$purpose->ownerRequired()) {
            throw MailRefused::invalid('purpose_has_no_owners', 'purpose', 'The addresses of this purpose do not belong to a person; the module that uses them manages them.');
        }

        return $purpose;
    }

    /**
     * @throws MailRefused
     */
    private function label(string $label): string
    {
        $label = trim($label);
        if ('' === $label) {
            throw MailRefused::invalid('label_required', 'label', 'Say what the address is for.');
        }
        if (mb_strlen($label) > InboundAddress::MAX_LABEL) {
            throw MailRefused::invalid('label_too_long', 'label', \sprintf('The label has 1 to %d characters.', InboundAddress::MAX_LABEL));
        }

        return $label;
    }

    private function unavailable(AddressUnavailable $reason): MailRefused
    {
        return match ($reason) {
            AddressUnavailable::NotConfigured => MailRefused::unavailable('inbound_mail_not_configured', 'Inbound mail is not set up on this server, so no address can be made. Ask the operator.'),
            AddressUnavailable::NoKey => MailRefused::unavailable('inbound_mail_no_key', 'This server has no key to seal inbound addresses. Ask the operator.'),
        };
    }

    /**
     * @throws MailRefused
     */
    private function me(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw MailRefused::forbidden();
        }

        return $userId;
    }

    private function view(InboundAddress $address): AddressView
    {
        return AddressView::of($address, $this->addresses->maskedTemplate(), $this->instance->today());
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
