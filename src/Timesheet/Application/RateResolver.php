<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\LegalEntities;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\Party;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Timesheet\Domain\RateSource;
use App\Timesheet\Domain\ResolvedRate;
use App\Work\Application\Projects;
use App\Work\Domain\Project;

/**
 * The hourly rate of time a person worked on a project, when a firm invoices
 * it: the project's own rate; else the rate of its client for that firm (the
 * firm's own invoicing defaults if it has them, else the ones the firms share,
 * as the client's other defaults resolve); else the person's rate. With none
 * of them, as for an internal project of a person without a rate, there is none.
 *
 * It reads what is stored now; an invoice keeps the rate it took, so a change
 * here never reaches time already invoiced. The project is read without the
 * sharing rules of Work: a rate is no view of the project, and only an owner
 * ever sees one.
 */
final readonly class RateResolver
{
    public function __construct(
        private Projects $projects,
        private People $people,
        private LegalEntities $firms,
        private ContactSettingsForFirm $contacts,
    ) {
    }

    /**
     * @throws UnknownRateTarget  for a project or a membership the organisation does not have
     * @throws UnknownLegalEntity for a firm it does not have
     */
    public function resolve(string $projectId, string $membershipId, string $firmId): ?ResolvedRate
    {
        $project = $this->projects->project($projectId);
        if (!$project instanceof Project) {
            throw new UnknownRateTarget('Unknown project.');
        }
        $membership = Id::isValid($membershipId) ? $this->people->membership($membershipId) : null;
        if (!$membership instanceof Membership) {
            throw new UnknownRateTarget('Unknown membership.');
        }
        $firm = $this->firms->get($firmId);

        $rate = $project->hourlyRate();
        if ($rate instanceof Money) {
            return new ResolvedRate($rate, RateSource::Project);
        }
        $client = $project->client();
        $rate = $client instanceof Party ? $this->contacts->defaultsFor($client, $firm)->hourlyRate() : null;
        if ($rate instanceof Money) {
            return new ResolvedRate($rate, RateSource::Client);
        }
        $rate = $membership->hourlyRate();

        return $rate instanceof Money ? new ResolvedRate($rate, RateSource::Person) : null;
    }
}
