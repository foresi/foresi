<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankFeed;
use App\Invoicing\Application\MailSecrets;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownFirmBankAccount;
use Psr\Clock\ClockInterface;

/**
 * Hands a bank account its inbound address, or a new one in place of the old.
 * The token is drawn here and leaves only inside the address returned: what
 * is stored is its hash and a sealed copy. Never log the address.
 */
final readonly class GenerateAddress
{
    public function __construct(
        private FirmBankAccounts $accounts,
        private BankFeeds $feeds,
        private InboundAddresses $addresses,
        private MailSecrets $secrets,
        private TransactionalWork $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the new address; any earlier one of the account stops at once
     *
     * @throws AddressRefused         with NotConfigured, NoKey or Archived
     * @throws UnknownFirmBankAccount also for an account of another tenant
     */
    public function generate(string $accountId): string
    {
        if (!$this->addresses->isConfigured()) {
            throw new AddressRefused(AddressRefusal::NotConfigured);
        }
        if (!$this->secrets->isConfigured()) {
            throw new AddressRefused(AddressRefusal::NoKey);
        }
        $token = InboundToken::generate();
        $hash = InboundToken::hash($token);
        $sealed = $this->secrets->seal($token);

        $this->transaction->run(function () use ($accountId, $hash, $sealed): void {
            $account = $this->accounts->get($accountId);
            if ($account->isArchived()) {
                throw new AddressRefused(AddressRefusal::Archived);
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $feed = $this->feeds->ofAccount($account->id());
            if ($feed instanceof BankFeed) {
                $feed->rotate($hash, $sealed, $now);

                return;
            }
            $this->feeds->add(BankFeed::open($account, $hash, $sealed, $now));
        });

        return $this->addresses->addressFor($token);
    }
}
