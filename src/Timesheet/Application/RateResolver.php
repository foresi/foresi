<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Domain\RateSource;
use Foresi\Timesheet\Domain\ResolvedRate;
use Foresi\Work\Application\Projects;
use Foresi\Work\Domain\Project;

/**
 * The hourly rate of time a person worked on a project, when a firm invoices
 * it: the project's own rate; else the rate among its client's invoicing
 * defaults; else the person's rate. With none
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
    ) {
    }

    /**
     * @throws UnknownRateTarget for a project or a membership the organisation does not have
     */
    public function resolve(string $projectId, string $membershipId): ?ResolvedRate
    {
        $project = $this->projects->project($projectId);
        if (!$project instanceof Project) {
            throw new UnknownRateTarget('Unknown project.');
        }
        $membership = Id::isValid($membershipId) ? $this->people->membership($membershipId) : null;
        if (!$membership instanceof Membership) {
            throw new UnknownRateTarget('Unknown membership.');
        }
        $rate = $project->hourlyRate();
        if ($rate instanceof Money) {
            return new ResolvedRate($rate, RateSource::Project);
        }
        $client = $project->client();
        $rate = $client instanceof Party ? $client->invoicingDefaults()->hourlyRate() : null;
        if ($rate instanceof Money) {
            return new ResolvedRate($rate, RateSource::Client);
        }
        $rate = $membership->hourlyRate();

        return $rate instanceof Money ? new ResolvedRate($rate, RateSource::Person) : null;
    }
}
