<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Work\Domain\Project;

/**
 * How the time of a project is billed and budgeted, kept beside the project
 * under its id: the project's own hourly rate, which comes before the rate of
 * its client and of the person who worked, and which of its time uses its time
 * budget (ProjectBudgetChange). A project without a row has no rate of its own
 * and counts all its time. Audited under the project, so a change shows in its
 * history. Whether new time is billable is a custom field of the project
 * (ProjectTimeFields), not kept here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_time_setting')]
class ProjectTimeSetting implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'hourly_rate_minor', type: 'bigint', nullable: true)]
    private ?int $hourlyRateMinor = null;

    #[ORM\Column(name: 'hourly_rate_currency', type: 'currency', length: 3, nullable: true, options: ['fixed' => true])]
    private ?Currency $hourlyRateCurrency = null;

    #[ORM\Column(name: 'budget_counting', type: 'string', length: 16, enumType: BudgetCounting::class, options: ['default' => 'all'])]
    private BudgetCounting $budgetCounting = BudgetCounting::All;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', unique: true, nullable: false, onDelete: 'CASCADE')]
        private readonly Project $project,
    ) {
        $this->id = Id::generate();
    }

    public function project(): Project
    {
        return $this->project;
    }

    /**
     * Null for none: the client's or the person's rate applies then. Zero is a
     * rate, for work that is not charged.
     *
     * @throws \DomainException for a negative rate
     */
    public function changeHourlyRate(?Money $rate): void
    {
        if (true === $rate?->isNegative()) {
            throw new \DomainException('An hourly rate is not negative.');
        }
        $this->hourlyRateMinor = $rate?->minor();
        $this->hourlyRateCurrency = $rate?->currency();
    }

    public function hourlyRate(): ?Money
    {
        return null === $this->hourlyRateMinor || !$this->hourlyRateCurrency instanceof Currency ? null : Money::ofMinor($this->hourlyRateMinor, $this->hourlyRateCurrency);
    }

    /**
     * Which time uses the project's time budget: all of it, or the billable time only.
     */
    public function countBudget(BudgetCounting $counting): void
    {
        $this->budgetCounting = $counting;
    }

    public function budgetCounting(): BudgetCounting
    {
        return $this->budgetCounting;
    }

    public function auditRoot(): object
    {
        return $this->project;
    }
}
