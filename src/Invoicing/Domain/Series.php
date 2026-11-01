<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A number series of a firm. The flag of the default series is true, on all
 * others NULL: NULLs never collide in a unique index, as on PartyAddress. A start
 * set here is a deliberate gap in the numbers, and the audit log of the series
 * records it. The numbers themselves come from NumberCounter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'series')]
#[ORM\Index(name: 'idx_series_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_series_firm', columns: ['legal_entity_id'])]
#[ORM\UniqueConstraint(name: 'uniq_series_name', columns: ['tenant_id', 'legal_entity_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_series_default', columns: ['legal_entity_id', 'kind', 'default_flag'])]
class Series implements TenantOwned, Audited, AuditRooted
{
    /** The name of the series a firm gets without setting one up; the screens translate it. */
    public const string STANDARD = 'standard';
    public const string STANDARD_FORMAT = '{YYYY}{NNNN}';
    public const int MAX_NAME_LENGTH = 100;

    private const string PERIOD = '/^(\d{4}|'.SeriesFormat::ALL_PERIODS.')$/D';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'string', length: SeriesFormat::MAX_LENGTH)]
    private string $format;

    #[ORM\Column(name: 'default_flag', type: 'boolean', nullable: true)]
    private ?bool $defaultFlag = null;

    /** @var array<int|string, int> the first number per period */
    #[ORM\Column(type: 'json')]
    private array $starts = [];

    #[ORM\Column(type: 'boolean')]
    private bool $archived = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        string $name,
        SeriesFormat $format,
        #[ORM\Column(type: 'string', length: 32, enumType: DocumentKind::class)]
        private readonly DocumentKind $kind,
    ) {
        $this->id = Id::generate();
        $this->name = $this->cleanName($name);
        $this->format = $format->toString();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->firm;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): DocumentKind
    {
        return $this->kind;
    }

    public function format(): SeriesFormat
    {
        return SeriesFormat::fromString($this->format);
    }

    public function isDefault(): bool
    {
        return true === $this->defaultFlag;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function rename(string $name): void
    {
        $this->name = $this->cleanName($name);
    }

    /**
     * Only for a series without documents; SeriesCatalog::hasDocuments() tells.
     */
    public function changeFormat(SeriesFormat $format): void
    {
        $this->format = $format->toString();
    }

    /**
     * At most one series of a firm and kind is the default; SeriesCatalog::makeDefault()
     * moves the flag in the order the unique index allows.
     */
    public function markDefault(): void
    {
        $this->defaultFlag = true;
    }

    public function clearDefault(): void
    {
        $this->defaultFlag = null;
    }

    /**
     * The first number of a period. The counter never goes back, so a start below
     * it has no effect.
     */
    public function startAt(int $number, string $period): void
    {
        if ($number < 1 || $number > SeriesFormat::MAX_SEQUENCE || 1 !== preg_match(self::PERIOD, $period)) {
            throw new \DomainException('A series starts at a positive number the counter holds, in a year or in all periods.');
        }

        $this->starts[$period] = $number;
    }

    public function startFor(string $period): int
    {
        return $this->starts[$period] ?? 1;
    }

    /**
     * Keeps its name and its numbers, and releases the default.
     */
    public function archive(): void
    {
        $this->archived = true;
        $this->defaultFlag = null;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \DomainException('A series needs a name.');
        }

        return $name;
    }
}
