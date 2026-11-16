<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * An HTTPS address of the organisation that receives the events it subscribes
 * to as signed Standard Webhooks requests, on behalf of the person who owns it.
 * Its signing secret is kept sealed; after a rotation the previous one signs
 * too until it expires. Neither ever reaches the audit log, and neither does
 * the start of the current run of failures, which only feeds the decision to
 * disable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_endpoint')]
#[ORM\Index(name: 'idx_webhook_endpoint_tenant_active', columns: ['tenant_id', 'active'])]
class WebhookEndpoint implements TenantOwned, Audited
{
    public const int DESCRIPTION_MAX_LENGTH = 255;
    /** Failing for this long disables an endpoint. */
    public const string FAILING_LIMIT = '+5 days';
    public const int MAX_VERSION = 32767;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: EndpointUrl::MAX_LENGTH)]
    private string $url;

    #[ORM\Column(type: 'string', length: self::DESCRIPTION_MAX_LENGTH, nullable: true)]
    private ?string $description;

    /** @var list<string> */
    #[ORM\Column(name: 'event_types', type: 'json')]
    private array $eventTypes;

    #[ORM\Column(type: 'smallint')]
    private int $version;

    #[AuditExcluded]
    #[ORM\Column(name: 'previous_secret', type: 'text', nullable: true)]
    private ?string $previousSecret = null;

    #[ORM\Column(name: 'previous_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $previousExpiresAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'disabled_reason', type: 'string', length: 32, nullable: true, enumType: DisabledReason::class)]
    private ?DisabledReason $disabledReason = null;

    #[AuditExcluded]
    #[ORM\Column(name: 'failing_since', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $failingSince = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        EndpointUrl $url,
        ?string $description,
        EventTypes $eventTypes,
        int $version,
        #[AuditExcluded]
        #[ORM\Column(type: 'text')]
        private string $secret,
        #[ORM\Column(name: 'owner_id', type: 'guid')]
        private string $ownerId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        if ('' === $ownerId) {
            throw new \InvalidArgumentException('A webhook endpoint belongs to a person.');
        }
        $this->url = $url->value;
        $this->description = $this->validDescription($description);
        $this->eventTypes = $eventTypes->patterns();
        $this->version = $this->validVersion($version);
        $this->updatedAt = $createdAt;
    }

    /**
     * @param string $sealedSecret the signing secret, sealed
     */
    public static function create(string $id, EndpointUrl $url, ?string $description, EventTypes $eventTypes, int $version, string $sealedSecret, string $ownerId, \DateTimeImmutable $now): self
    {
        return new self($id, $url, $description, $eventTypes, $version, $sealedSecret, $ownerId, $now);
    }

    /**
     * Replaces what is given and keeps the rest; a blank description clears it.
     */
    public function change(\DateTimeImmutable $now, ?EndpointUrl $url = null, ?string $description = null, ?EventTypes $eventTypes = null, ?int $version = null): void
    {
        if ($url instanceof EndpointUrl) {
            $this->url = $url->value;
        }
        if (null !== $description) {
            $this->description = $this->validDescription($description);
        }
        if ($eventTypes instanceof EventTypes) {
            $this->eventTypes = $eventTypes->patterns();
        }
        if (null !== $version) {
            $this->version = $this->validVersion($version);
        }
        $this->updatedAt = $now;
    }

    /**
     * Signs with a new secret from now on, and with the current one too until
     * $previousExpiresAt. A previous secret that would expire at once is dropped.
     *
     * @param string $sealedNew the new signing secret, sealed
     */
    public function rotate(string $sealedNew, \DateTimeImmutable $previousExpiresAt, \DateTimeImmutable $now): void
    {
        $keep = $previousExpiresAt > $now;
        $this->previousSecret = $keep ? $this->secret : null;
        $this->previousExpiresAt = $keep ? $previousExpiresAt : null;
        $this->secret = $sealedNew;
        $this->updatedAt = $now;
    }

    /**
     * The sealed secrets a request is signed with now: the current one first.
     *
     * @return list<string>
     */
    public function signingSecrets(\DateTimeImmutable $now): array
    {
        $secrets = [$this->secret];
        if (null !== $this->previousSecret && $this->previousExpiresAt instanceof \DateTimeImmutable && $now < $this->previousExpiresAt) {
            $secrets[] = $this->previousSecret;
        }

        return $secrets;
    }

    public function disable(DisabledReason $reason, \DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->disabledReason = $reason;
        $this->updatedAt = $now;
    }

    /**
     * Receiving again starts a new run: the reason and the past failures are gone.
     */
    public function enable(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->disabledReason = null;
        $this->failingSince = null;
        $this->updatedAt = $now;
    }

    /**
     * Notes a failed scheduled attempt. True when the endpoint has failed for
     * five days without a success: the caller then disables it.
     */
    public function failed(\DateTimeImmutable $now): bool
    {
        $this->failingSince ??= $now;

        return $now >= $this->failingSince->modify(self::FAILING_LIMIT);
    }

    public function succeeded(): void
    {
        $this->failingSince = null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function url(): EndpointUrl
    {
        return new EndpointUrl($this->url);
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function eventTypes(): EventTypes
    {
        return EventTypes::of($this->eventTypes);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function disabledReason(): ?DisabledReason
    {
        return $this->disabledReason;
    }

    public function failingSince(): ?\DateTimeImmutable
    {
        return $this->failingSince;
    }

    public function previousExpiresAt(): ?\DateTimeImmutable
    {
        return $this->previousExpiresAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function validDescription(?string $description): ?string
    {
        $description = null === $description ? '' : trim($description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A webhook endpoint has a description of at most %d characters.', self::DESCRIPTION_MAX_LENGTH));
        }

        return '' === $description ? null : $description;
    }

    private function validVersion(int $version): int
    {
        if ($version < 1 || $version > self::MAX_VERSION) {
            throw new \InvalidArgumentException('A webhook endpoint is pinned to a positive event version.');
        }

        return $version;
    }
}
