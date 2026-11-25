<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Domain\BankFeed;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Application\UnknownFirmBankAccount;
use Psr\Clock\ClockInterface;

/**
 * Hands a bank account its inbound address, or a new one in place of the old.
 * Mail draws the token, and it leaves only inside the address returned: what
 * is stored is its hash and a sealed copy. Never log the address.
 */
final readonly class GenerateAddress
{
    public function __construct(
        private FirmBankAccounts $accounts,
        private BankFeeds $feeds,
        private AddressIssuer $issuer,
        private TransactionalWork $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the new address; any earlier one of the account stops at once
     *
     * @throws AddressRefused         with NotConfigured, NoKey or Archived
     * @throws UnknownFirmBankAccount for an unknown account
     */
    public function generate(string $accountId): string
    {
        $unavailable = $this->issuer->unavailable(BankFeedPurpose::KEY);
        if ($unavailable instanceof AddressUnavailable) {
            throw new AddressRefused($this->refusal($unavailable));
        }

        try {
            return $this->transaction->run(function () use ($accountId): string {
                $account = $this->accounts->get($accountId);
                if ($account->isArchived()) {
                    throw new AddressRefused(AddressRefusal::Archived);
                }
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $feed = $this->feeds->ofAccount($account->id());
                if ($feed instanceof BankFeed) {
                    $issued = $this->issuer->rotate($feed->addressId());
                    $feed->rotated($now);

                    return $issued->address;
                }
                $issued = $this->issuer->issue(BankFeedPurpose::KEY, null, BankFeedPurpose::LABEL);
                $this->feeds->add(BankFeed::open($account, $issued->id, $now));

                return $issued->address;
            });
        } catch (AddressNotIssued $refused) {
            throw new AddressRefused($this->refusal($refused->reason));
        }
    }

    private function refusal(AddressUnavailable $reason): AddressRefusal
    {
        return match ($reason) {
            AddressUnavailable::NotConfigured => AddressRefusal::NotConfigured,
            AddressUnavailable::NoKey => AddressRefusal::NoKey,
        };
    }
}
