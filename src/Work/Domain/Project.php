<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Party\Domain\Party;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A body of work of the organisation, for a client or internal. It belongs to
 * the tenant, not to one of its firms: the firms of an organisation share their
 * people and their projects, and only an invoice needs a firm.
 *
 * Statuses, sections and tasks point here and are read by query; the project
 * maps no collection of them, as the audit log rejects changed collections.
 *
 * Time logged on the project is billable by default when the project is for a
 * client and not when it is internal (Kimai, Toggl); the default is set when
 * the project is created, not when its client changes, and each entry may
 * differ from it.
 *
 * An hour on the project may be billed at a rate of its own, which comes
 * before the rate of its client and of the person who worked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project')]
#[ORM\Index(name: 'idx_project_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_project_client', columns: ['client_id'])]
class Project implements TenantOwned, Audited
{
    public const int MAX_NAME_LENGTH = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: 'string', length: 16, enumType: ProjectVisibility::class, options: ['default' => 'organisation'])]
    private ProjectVisibility $visibility = ProjectVisibility::Organisation;

    #[ORM\Column(name: 'billable_by_default', type: 'boolean', options: ['default' => false])]
    private bool $billableByDefault;

    #[ORM\Column(name: 'hourly_rate_minor', type: 'bigint', nullable: true)]
    private ?int $hourlyRateMinor = null;

    #[ORM\Column(name: 'hourly_rate_currency', type: 'string', length: 3, nullable: true, enumType: Currency::class, options: ['fixed' => true])]
    private ?Currency $hourlyRateCurrency = null;

    public function __construct(
        string $name,
        /** The contact the work is for, of any kind; null for internal work. */
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: true, onDelete: 'RESTRICT')]
        private ?Party $client,
        #[ORM\Column(name: 'workflow_template', type: 'string', length: 16, enumType: WorkflowTemplate::class)]
        private readonly WorkflowTemplate $workflowTemplate,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->rename($name);
        $this->billableByDefault = $client instanceof Party;
    }

    public function rename(string $name): void
    {
        $this->name = Name::of($name, self::MAX_NAME_LENGTH, 'A project');
    }

    /**
     * Plain text for now; Markdown arrives later in the same column.
     */
    public function describe(string $description): void
    {
        $this->description = $description;
    }

    public function assignClient(?Party $client): void
    {
        $this->client = $client;
    }

    /**
     * Archiving twice keeps the first date.
     */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt ??= $now;
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    /**
     * Open to the whole organisation, as a new project is, or only to the
     * people it is shared with.
     */
    public function changeVisibility(ProjectVisibility $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function visibility(): ProjectVisibility
    {
        return $this->visibility;
    }

    /**
     * Whether time logged on the project is billable unless said otherwise.
     */
    public function billTimeByDefault(bool $billable): void
    {
        $this->billableByDefault = $billable;
    }

    public function billsTimeByDefault(): bool
    {
        return $this->billableByDefault;
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

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function client(): ?Party
    {
        return $this->client;
    }

    public function workflowTemplate(): WorkflowTemplate
    {
        return $this->workflowTemplate;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
