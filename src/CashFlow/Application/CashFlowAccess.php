<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Application\UnknownFirmBankAccount;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;

/**
 * Who works with the cash flow: the owners and the members of the
 * organisation, never its guests. Every use case asks here first, and finds
 * the firm of the instance, the accounts and the contacts it names here, so an
 * unknown record is refused.
 */
final readonly class CashFlowAccess
{
    public function __construct(
        private StaffCheck $staff,
        private AuditActor $actor,
        private InstanceFirm $firm,
        private FirmBankAccounts $accounts,
        private PartyQuery $parties,
    ) {
    }

    /**
     * @throws CashFlowRefused forbidden
     */
    public function denyUnlessStaff(): void
    {
        if (null === $this->actor->userId() || !$this->staff->isStaff()) {
            throw CashFlowRefused::forbidden();
        }
    }

    /**
     * The one firm of the instance (ADR 0039).
     *
     * @throws CashFlowRefused not_found before the first-run setup
     */
    public function firm(): LegalEntity
    {
        try {
            return $this->firm->get();
        } catch (NoFirmYet) {
            throw CashFlowRefused::notFound('The instance has no firm yet: its first-run setup has not happened.');
        }
    }

    /**
     * @throws CashFlowRefused not_found
     */
    public function account(string $id): FirmBankAccount
    {
        try {
            return $this->accounts->get($id);
        } catch (UnknownFirmBankAccount) {
            throw CashFlowRefused::notFound('The organisation has no bank account of a firm with this id. Find it in balance.accounts of foresi_cash_flow_forecast.');
        }
    }

    /**
     * @return list<FirmBankAccount> the active accounts of the firm
     */
    public function accountsOf(LegalEntity $firm): array
    {
        return $this->accounts->of($firm);
    }

    /**
     * @throws CashFlowRefused invalid counterparty_unknown
     */
    public function counterparty(string $id): Party
    {
        return $this->parties->find($id) ?? throw CashFlowRefused::invalid('counterparty_unknown', 'counterpartyId', 'The organisation has no contact with this id. Find it with foresi_search_contacts.');
    }

    /**
     * The contacts whose name matches $text, for the counterparty field of a
     * planned item: the first page in name order, archived ones left out.
     *
     * @return list<array{id: string, label: string, hint: ?string}>
     *
     * @throws CashFlowRefused forbidden
     */
    public function contacts(string $text): array
    {
        $this->denyUnlessStaff();

        return array_map(static fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber,
        ], $this->parties->search($text, null, false, 'name', 1)->rows);
    }
}
