<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod as StatusPeriodView;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use Foresi\TaxesAndContributions\Domain\AuthorityAccount;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;
use Foresi\TaxesAndContributions\Domain\YearInputs;

/**
 * The owner profile as the screens, the API and MCP read it; only the owner
 * reads it. Before anything is entered it is empty.
 *
 * The VAT payer statuses are the firm's (Party's FirmVatStatuses), never
 * stored here: a registered stretch is "yes" and any other "no", from its
 * day, and the undated stretch that held from the start from 1970-01-01.
 */
final readonly class ProfileReads
{
    public const string FIRM_VAT = 'firm-vat:';
    public const string FROM_THE_START = '1970-01-01';

    public function __construct(private TaxesAccess $access, private TaxesStore $store, private FirmVatStatuses $firmVat)
    {
    }

    /**
     * @throws TaxesRefused owner_only
     */
    public function profile(): ProfileView
    {
        $this->access->denyUnlessOwner();

        return $this->view();
    }

    /**
     * The profile without asking who reads, for the estimate flows of the
     * cash flow alone: Cash flow shows them to anyone but the owner only as
     * category totals (B7), so nothing of the profile reaches them.
     */
    public function forEstimateFlows(): ProfileView
    {
        return $this->view();
    }

    private function view(): ProfileView
    {
        $profile = $this->store->profile();
        $years = [];
        foreach ($profile instanceof OwnerProfile ? $profile->years() : [] as $inputs) {
            $years[$inputs->year()] = $inputs->view();
        }

        return new ProfileView(
            $this->statuses(),
            $years,
            array_map(static fn (AuthorityAccount $account): AuthorityAccountView => $account->view(), $profile instanceof OwnerProfile ? $profile->authorities() : []),
        );
    }

    /**
     * The stored statuses with the firm's VAT payer days in their place among
     * the kinds, each kind oldest first.
     *
     * @return list<StatusPeriodView>
     */
    private function statuses(): array
    {
        $stored = array_map(static fn (StatusPeriod $status): StatusPeriodView => $status->view(), $this->store->statuses());
        $vat = array_map($this->vatPayer(...), $this->firmVat->stretches());
        $statuses = [];
        foreach (StatusKind::cases() as $kind) {
            $ofKind = StatusKind::VatPayer === $kind ? $vat : array_filter($stored, static fn (StatusPeriodView $status): bool => $kind === $status->kind);
            array_push($statuses, ...array_values($ofKind));
        }

        return $statuses;
    }

    private function vatPayer(VatStretch $stretch): StatusPeriodView
    {
        return new StatusPeriodView(
            self::FIRM_VAT.$stretch->id,
            StatusKind::VatPayer,
            VatRegistration::Registered === $stretch->status ? 'yes' : 'no',
            $stretch->validFrom ?? new \DateTimeImmutable(self::FROM_THE_START),
        );
    }

    /**
     * The inputs of the year, or its defaults when nothing is entered for it.
     *
     * @throws TaxesRefused owner_only
     */
    public function year(int $year): YearInputsView
    {
        $this->access->denyUnlessOwner();

        return $this->store->profile()?->year($year)?->view() ?? YearInputs::of(new YearInputsView($year))->view();
    }
}
