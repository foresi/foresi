<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use Foresi\TaxesAndContributions\Domain\AuthorityAccount;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;
use Foresi\TaxesAndContributions\Domain\YearInputs;

/**
 * The owner profile as the screens, the API and MCP read it; only the owner
 * reads it. Before anything is entered it is empty.
 */
final readonly class ProfileReads
{
    public function __construct(private TaxesAccess $access, private TaxesStore $store)
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
            array_map(static fn (StatusPeriod $status): \Foresi\TaxesAndContributions\Contract\StatusPeriod => $status->view(), $this->store->statuses()),
            $years,
            array_map(static fn (AuthorityAccount $account): \Foresi\TaxesAndContributions\Contract\AuthorityAccountView => $account->view(), $profile instanceof OwnerProfile ? $profile->authorities() : []),
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
