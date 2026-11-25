<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Mail\Contract\InboundPurpose;
use Foresi\Mail\Contract\IssuedAddress;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Shared\Application\Sealer;
use Foresi\Shared\Application\SealerUnavailable;
use Psr\Clock\ClockInterface;

/**
 * The inbound addresses of this server, built from its address template, with
 * a fresh token each. Only the token's hash is stored, and a sealed copy for a
 * purpose that shows its addresses again. Never log an address.
 */
final readonly class IssueAddresses implements AddressIssuer
{
    public function __construct(
        private InboundAddresses $addresses,
        private InboundAddressStore $store,
        private InboundPurposes $purposes,
        private Sealer $sealer,
        private MailTransaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    public function issue(string $purpose, ?string $ownerUserId, string $label): IssuedAddress
    {
        $registered = $this->purpose($purpose);
        $this->refuseWhenUnavailable($registered);
        $token = InboundToken::generate();
        $sealed = $this->sealed($registered, $token);
        $address = new InboundAddress($purpose, $ownerUserId, $label, InboundToken::hash($token), $sealed, $this->now());
        $this->transaction->run(function () use ($address): void {
            $this->store->add($address);
        });

        return new IssuedAddress($address->id(), $this->addressFor($token));
    }

    public function rotate(string $addressId): IssuedAddress
    {
        $address = $this->store->get($addressId) ?? throw new \DomainException('No inbound address has this id.');
        $registered = $this->purpose($address->purpose());
        $this->refuseWhenUnavailable($registered);
        $token = InboundToken::generate();
        $sealed = $this->sealed($registered, $token);
        $this->transaction->run(static function () use ($address, $token, $sealed): void {
            $address->rotate(InboundToken::hash($token), $sealed);
        });

        return new IssuedAddress($address->id(), $this->addressFor($token));
    }

    public function reveal(string $addressId): ?string
    {
        $address = $this->store->get($addressId);
        if (!$address instanceof InboundAddress || $address->isRevoked()) {
            return null;
        }
        $sealed = $address->tokenSealed();
        $purpose = $this->purposes->get($address->purpose());
        if (null === $sealed || !$purpose instanceof InboundPurpose || !$purpose->reshowable()) {
            return null;
        }
        try {
            $token = $this->sealer->open($sealed);
        } catch (SealerUnavailable) {
            throw new AddressNotIssued(AddressUnavailable::NoKey);
        }

        return $this->addressFor($token);
    }

    public function revoke(string $addressId): void
    {
        $address = $this->store->get($addressId);
        if (!$address instanceof InboundAddress) {
            return;
        }
        $this->transaction->run(function () use ($address): void {
            $address->revoke($this->now());
        });
    }

    public function unavailable(string $purpose): ?AddressUnavailable
    {
        if (!$this->addresses->isConfigured()) {
            return AddressUnavailable::NotConfigured;
        }
        $registered = $this->purposes->get($purpose);
        if ($registered instanceof InboundPurpose && $registered->reshowable() && !$this->sealer->isConfigured()) {
            return AddressUnavailable::NoKey;
        }

        return null;
    }

    public function masked(string $text): string
    {
        return $this->addresses->masked($text);
    }

    private function purpose(string $key): InboundPurpose
    {
        return $this->purposes->get($key) ?? throw new \DomainException(\sprintf('No installed module takes mail for the purpose "%s".', $key));
    }

    private function refuseWhenUnavailable(InboundPurpose $purpose): void
    {
        $unavailable = $this->unavailable($purpose->key());
        if ($unavailable instanceof AddressUnavailable) {
            throw new AddressNotIssued($unavailable);
        }
    }

    private function sealed(InboundPurpose $purpose, #[\SensitiveParameter] string $token): ?string
    {
        if (!$purpose->reshowable()) {
            return null;
        }
        try {
            return $this->sealer->seal($token);
        } catch (SealerUnavailable) {
            throw new AddressNotIssued(AddressUnavailable::NoKey);
        }
    }

    private function addressFor(#[\SensitiveParameter] string $token): string
    {
        try {
            return $this->addresses->addressFor($token);
        } catch (AddressNotConfigured) {
            throw new AddressNotIssued(AddressUnavailable::NotConfigured);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
