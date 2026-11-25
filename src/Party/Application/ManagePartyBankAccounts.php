<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\Event\ContactUpdated;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Shared\Contract\Event\EventRecorder;

/**
 * The bank accounts of a contact, as its Bank accounts tab changes them. The
 * first account is the primary one; making another primary demotes the old
 * one, and deleting the primary hands it to the next account. An IBAN is there
 * once per contact. Once stored, the number stays; a wrong one is deleted and
 * added again.
 */
final readonly class ManagePartyBankAccounts
{
    public const int MAX_LABEL = 100;

    public function __construct(
        private PartyBankAccounts $accounts,
        private PartyDetails $details,
        private AccountNumbers $numbers,
        private TransactionalWork $transaction,
        private PartyAccess $access,
        private EventRecorder $events,
    ) {
    }

    /**
     * @param string $number an IBAN, or a domestic number with a bank code of the contact's country
     *
     * @throws PartyNotAllowed    for a guest
     * @throws ContactPartRefused with every field that is wrong
     */
    public function add(Party $party, string $number, string $bic, string $label, bool $primary): PartyBankAccount
    {
        $this->access->denyUnlessMayChange();
        $reasons = $this->check($bic, $label);
        $country = $this->details->billingAddress($party)?->address()->countryCode ?? '';
        $iban = '' === trim($number) ? null : $this->numbers->toIban($number, $country);
        $existing = $this->details->bankAccounts($party);
        if ('' === trim($number)) {
            $reasons['number'] = ContactPartRefused::REQUIRED;
        } elseif (!$iban instanceof \Foresi\Party\Domain\Iban) {
            $reasons['number'] = ContactPartRefused::ACCOUNT_NUMBER;
        } elseif (array_any($existing, static fn (PartyBankAccount $account): bool => $account->iban()->toString() === $iban->toString())) {
            $reasons['number'] = ContactPartRefused::DUPLICATE_ACCOUNT;
        }
        if ([] !== $reasons || !$iban instanceof \Foresi\Party\Domain\Iban) {
            throw new ContactPartRefused($reasons);
        }
        $account = new PartyBankAccount($party, $iban, $this->bic($bic), $label, $primary || [] === $existing);
        $this->transaction->run(function () use ($party, $account): void {
            $this->accounts->add($account);
            $this->changed($party);
        });

        return $account;
    }

    /**
     * The BIC and the label; the number stays.
     *
     * @throws PartyNotAllowed    for a guest
     * @throws UnknownParty       when the account is not one of the contact's
     * @throws ContactPartRefused with every field that is wrong
     */
    public function change(Party $party, string $accountId, string $bic, string $label): PartyBankAccount
    {
        $this->access->denyUnlessMayChange();
        $account = $this->account($party, $accountId);
        $reasons = $this->check($bic, $label);
        if ([] !== $reasons) {
            throw new ContactPartRefused($reasons);
        }
        $this->transaction->run(function () use ($party, $account, $bic, $label): void {
            $account->changeBic($this->bic($bic));
            $account->relabel($label);
            $this->changed($party);
        });

        return $account;
    }

    /**
     * @throws PartyNotAllowed for a guest
     * @throws UnknownParty    when the account is not one of the contact's
     */
    public function makePrimary(Party $party, string $accountId): void
    {
        $this->access->denyUnlessMayChange();
        $account = $this->account($party, $accountId);
        if (!$account->isPrimary()) {
            $this->transaction->run(function () use ($party, $account): void {
                $this->accounts->makePrimary($account);
                $this->changed($party);
            });
        }
    }

    /**
     * Deletes the account; when it was the primary one, the next account becomes primary.
     *
     * @throws PartyNotAllowed for a guest
     * @throws UnknownParty    when the account is not one of the contact's
     */
    public function remove(Party $party, string $accountId): void
    {
        $this->access->denyUnlessMayChange();
        $account = $this->account($party, $accountId);
        $next = array_find($this->details->bankAccounts($party), static fn (PartyBankAccount $other): bool => $other !== $account);
        $this->transaction->run(function () use ($party, $account, $next): void {
            $this->accounts->remove($account);
            $this->changed($party);
            if ($account->isPrimary() && $next instanceof PartyBankAccount) {
                // The deleted primary leaves the index before the next one takes it.
                $account->demote();
                $this->accounts->makePrimary($next);
            }
        });
    }

    /**
     * @throws UnknownParty when the account is not one of the contact's
     */
    public function account(Party $party, string $accountId): PartyBankAccount
    {
        return array_find($this->details->bankAccounts($party), static fn (PartyBankAccount $account): bool => $account->id() === $accountId)
            ?? throw new UnknownParty('The contact has no bank account with this id.');
    }

    /**
     * @return array<string, string>
     */
    private function check(string $bic, string $label): array
    {
        $reasons = [];
        $normalised = $this->bic($bic);
        if (null !== $normalised && 1 !== preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $normalised)) {
            $reasons['bic'] = ContactPartRefused::BIC;
        }
        if (mb_strlen(trim($label)) > self::MAX_LABEL) {
            $reasons['label'] = ContactPartRefused::TOO_LONG;
        }

        return $reasons;
    }

    private function bic(string $bic): ?string
    {
        $bic = strtoupper((string) preg_replace('/\s+/', '', $bic));

        return '' === $bic ? null : $bic;
    }

    /**
     * Webhooks and listeners learn that the contact changed, as they do for its form.
     */
    private function changed(Party $party): void
    {
        $this->events->record(new ContactUpdated($party->id(), ContactKind::from($party->kind()->value)));
    }
}
