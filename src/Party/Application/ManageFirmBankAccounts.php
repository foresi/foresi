<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\BankAccountUsage;
use Foresi\Party\Domain\DomesticBankAccount;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\InvalidIban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Currency;
use Psr\Clock\ClockInterface;

/**
 * The one path that writes the firm's bank accounts. The first active account is
 * the primary one, and only ever one is; making another primary demotes the old
 * one, and archiving the primary hands it to the next active account. Primary
 * moves only by making another account primary, so with active accounts there
 * is always one. An account is never deleted, only archived.
 */
final readonly class ManageFirmBankAccounts
{
    /** "19-2000145399/0800": a domestic number, a slash and a bank code. */
    private const string DOMESTIC = '~^([0-9\s-]+)/\s*([0-9]{1,4})$~D';

    /**
     * @param iterable<DomesticBankAccount> $domesticAccounts
     */
    public function __construct(
        private InstanceFirm $firm,
        private FirmBankAccounts $accounts,
        private TransactionalWork $transaction,
        private BankAccountUsage $usage,
        private ClockInterface $clock,
        private iterable $domesticAccounts,
    ) {
    }

    /**
     * @param string $iban an IBAN, or a domestic account number of the firm's country
     *
     * @throws BankAccountRefused with InvalidIban, UnknownCurrency or Duplicate
     * @throws NoFirmYet          before the first-run setup
     * @throws \Exception         the storage's error when the commit fails, for one when a concurrent open took the IBAN or the primary place
     */
    public function open(string $iban, string $currency, string $label, bool $primary): FirmBankAccount
    {
        return $this->transaction->run(function () use ($iban, $currency, $label, $primary): FirmBankAccount {
            // Two opens at once would both find no primary account and both take it.
            $firm = $this->firm->lock();
            $number = $this->iban($firm, $iban);
            $currency = $this->currency($currency);
            if ($this->accounts->ofIban($number->toString()) instanceof FirmBankAccount) {
                throw new BankAccountRefused(BankAccountRefusal::Duplicate);
            }

            $first = !$this->accounts->primary() instanceof FirmBankAccount;
            $account = FirmBankAccount::open($number, $currency, $label, $this->accounts->nextPosition());
            $this->accounts->add($account);
            if ($primary || $first) {
                $this->accounts->makePrimary($account);
            }

            return $account;
        });
    }

    /**
     * Primary false leaves a primary account primary: another one takes it over.
     *
     * @throws BankAccountRefused     with UnknownCurrency, CurrencyLocked or ArchivedPrimary
     * @throws UnknownFirmBankAccount also for an unknown account
     */
    public function change(string $id, string $label, string $currency, bool $primary): FirmBankAccount
    {
        return $this->transaction->run(function () use ($id, $label, $currency, $primary): FirmBankAccount {
            // A movement is recorded under the same lock, so none slips in after the look below.
            $account = $this->accounts->lock($id);
            $currency = $this->currency($currency);
            if ($currency !== $account->currency() && $this->usage->hasMovements($account->id())) {
                throw new BankAccountRefused(BankAccountRefusal::CurrencyLocked);
            }
            if ($primary && $account->isArchived()) {
                throw new BankAccountRefused(BankAccountRefusal::ArchivedPrimary);
            }

            $account->relabel($label);
            $account->changeCurrency($currency);
            if ($primary && !$account->isPrimary()) {
                $this->accounts->makePrimary($account);
            }

            return $account;
        });
    }

    /**
     * Repeatable. Archiving the primary account makes the next active one primary.
     *
     * @throws UnknownFirmBankAccount also for an unknown account
     */
    public function archive(string $id): void
    {
        $this->transaction->run(function () use ($id): void {
            $account = $this->accounts->get($id);
            if ($account->isArchived()) {
                return;
            }
            $wasPrimary = $account->isPrimary();
            $account->archive(\DateTimeImmutable::createFromInterface($this->clock->now()));
            if (!$wasPrimary) {
                return;
            }
            foreach ($this->accounts->all() as $next) {
                if ($next !== $account) {
                    $this->accounts->makePrimary($next);

                    return;
                }
            }
        });
    }

    /**
     * Repeatable. A restored account is primary when there is no other.
     *
     * @throws UnknownFirmBankAccount also for an unknown account
     */
    public function restore(string $id): void
    {
        $this->transaction->run(function () use ($id): void {
            $account = $this->accounts->get($id);
            if (!$account->isArchived()) {
                return;
            }
            $account->restore();
            if (!$this->accounts->primary() instanceof FirmBankAccount) {
                $this->accounts->makePrimary($account);
            }
        });
    }

    private function iban(LegalEntity $firm, string $raw): Iban
    {
        try {
            return Iban::fromString($raw);
        } catch (InvalidIban) {
        }

        $country = $firm->address()->countryCode;
        if (1 === preg_match(self::DOMESTIC, trim($raw), $parts)) {
            foreach ($this->domesticAccounts as $accounts) {
                if ($accounts->supports($country)) {
                    try {
                        return $accounts->toIban($parts[1], $parts[2]);
                    } catch (InvalidIban) {
                        break;
                    }
                }
            }
        }

        throw new BankAccountRefused(BankAccountRefusal::InvalidIban);
    }

    private function currency(string $code): Currency
    {
        return Currency::tryFrom($code) ?? throw new BankAccountRefused(BankAccountRefusal::UnknownCurrency);
    }
}
