<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Application\ProjectTimeSettingStore;
use Foresi\Timesheet\Contract\ProjectRateSource;
use Foresi\Work\Application\Projects;
use Foresi\Work\Domain\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The hourly rate of time a person worked on a project, when a firm invoices
 * it: the rate a module sets for the project (ProjectRateSource, such as the
 * hourly deal line it is linked to); else the project's own rate; else the rate
 * among its client's invoicing defaults; else the person's rate. With none
 * of them, as for an internal project of a person without a rate, there is none.
 *
 * It reads what is stored now; an invoice keeps the rate it took, so a change
 * here never reaches time already invoiced. The project is read without the
 * sharing rules of Work: a rate is no view of the project, and only an owner
 * ever sees one.
 */
final readonly class RateResolver
{
    /**
     * @param iterable<ProjectRateSource> $sources asked in turn
     */
    public function __construct(
        private Projects $projects,
        private People $people,
        private ProjectTimeSettingStore $settings,
        private PartyInvoicingDefaults $partyDefaults,
        #[AutowireIterator(ProjectRateSource::TAG)]
        private iterable $sources = [],
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
        foreach ($this->sources as $source) {
            $rate = $source->rateFor($project->id());
            if ($rate instanceof Money) {
                return new ResolvedRate($rate, RateSource::DealLine);
            }
        }
        $rate = $this->settings->setting($project)?->hourlyRate();
        if ($rate instanceof Money) {
            return new ResolvedRate($rate, RateSource::Project);
        }
        $client = $project->client();
        $rate = $client instanceof Party ? $this->partyDefaults->of($client->id())->hourlyRate() : null;
        if ($rate instanceof Money) {
            return new ResolvedRate($rate, RateSource::Client);
        }
        $rate = $membership->hourlyRate();

        return $rate instanceof Money ? new ResolvedRate($rate, RateSource::Person) : null;
    }
}
