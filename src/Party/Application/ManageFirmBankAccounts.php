<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\DomesticBankAccount;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use Psr\Clock\ClockInterface;

/**
 * The one path that writes a firm's bank accounts. The first active account is
 * the primary one, and only ever one is; making another primary demotes the old
 * one, and archiving the primary hands it to the next active account. Primary
 * moves only by making another account primary, so a firm with active accounts
 * always has one. An account is never deleted, only archived.
 */
final readonly class ManageFirmBankAccounts
{
    /** "19-2000145399/0800": a domestic number, a slash and a bank code. */
    private const string DOMESTIC = '~^([0-9\s-]+)/\s*([0-9]{1,4})$~D';

    /**
     * @param iterable<DomesticBankAccount> $domesticAccounts
     */
    public function __construct(
        private LegalEntities $firms,
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
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function open(string $firmId, string $iban, string $currency, string $label, bool $primary): FirmBankAccount
    {
        return $this->transaction->run(function () use ($firmId, $iban, $currency, $label, $primary): FirmBankAccount {
            // Two opens at once would both find no primary account and both take it.
            $firm = $this->firms->lock($firmId);
            $number = $this->iban($firm, $iban);
            $currency = $this->currency($currency);
            if ($this->accounts->ofIban($firm, $number->toString()) instanceof FirmBankAccount) {
                throw new BankAccountRefused(BankAccountRefusal::Duplicate);
            }

            $first = !$this->accounts->primaryOf($firm) instanceof FirmBankAccount;
            $account = FirmBankAccount::open($firm, $number, $currency, $label, $this->accounts->nextPosition($firm));
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
     * @throws UnknownFirmBankAccount also for an account of another tenant
     */
    public function change(string $id, string $label, string $currency, bool $primary): FirmBankAccount
    {
        return $this->transaction->run(function () use ($id, $label, $currency, $primary): FirmBankAccount {
            $account = $this->accounts->get($id);
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
     * @throws UnknownFirmBankAccount also for an account of another tenant
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
            foreach ($this->accounts->of($account->firm()) as $next) {
                if ($next !== $account) {
                    $this->accounts->makePrimary($next);

                    return;
                }
            }
        });
    }

    /**
     * Repeatable. A restored account is primary when the firm has no other.
     *
     * @throws UnknownFirmBankAccount also for an account of another tenant
     */
    public function restore(string $id): void
    {
        $this->transaction->run(function () use ($id): void {
            $account = $this->accounts->get($id);
            if (!$account->isArchived()) {
                return;
            }
            $account->restore();
            if (!$this->accounts->primaryOf($account->firm()) instanceof FirmBankAccount) {
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
