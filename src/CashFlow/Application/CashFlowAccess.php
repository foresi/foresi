<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;

/**
 * Who works with the cash flow: the owners and the members of the
 * organisation, never its guests. Every use case asks here first, and finds
 * the firms, accounts and contacts it names here, so a record of another
 * tenant is refused as unknown.
 */
final readonly class CashFlowAccess
{
    public function __construct(
        private StaffCheck $staff,
        private AuditActor $actor,
        private LegalEntities $firms,
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
     * @throws CashFlowRefused not_found
     */
    public function firm(string $id): LegalEntity
    {
        try {
            return $this->firms->get($id);
        } catch (UnknownLegalEntity) {
            throw CashFlowRefused::notFound('The organisation has no firm with this id. Find it with foresi_list_firms.');
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
}
