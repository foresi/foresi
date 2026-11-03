<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * The firm's own SMTP server, through which its documents are sent. One per firm.
 * The password is held only sealed, and never written to the audit log. Whether
 * the host may be reached is checked by the mail host policy, at save and again
 * before every connection, since a name can change what it points to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_mail_server')]
#[ORM\Index(name: 'idx_firm_mail_server_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_firm_mail_server_firm', columns: ['legal_entity_id'])]
class FirmMailServer implements TenantOwned, Audited, AuditRooted
{
    /** Submission ports only: 25 is for servers talking to servers, and often blocked. */
    public const array PORTS = [465, 587, 2525];

    private const string HOST_NAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 253)]
    private string $host;

    #[ORM\Column(type: 'integer')]
    private int $port;

    #[ORM\Column(type: 'string', length: 16, enumType: MailEncryption::class)]
    private MailEncryption $encryption;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    #[AuditExcluded]
    #[ORM\Column(name: 'secret_sealed', type: 'text')]
    private string $sealedSecret;

    #[ORM\Column(name: 'from_address', type: 'string', length: 254)]
    private string $fromAddress;

    #[ORM\Column(name: 'from_name', type: 'string', length: 255)]
    private string $fromName;

    #[ORM\Column(name: 'verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt;

    #[ORM\Column(name: 'last_test_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastTestAt;

    /** The safe reason of the last test that failed; null when it passed. */
    #[ORM\Column(name: 'last_test_error', type: 'string', length: 255, nullable: true)]
    private ?string $lastTestError;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        string $host,
        int $port,
        MailEncryption $encryption,
        string $username,
        string $sealedSecret,
        string $fromAddress,
        string $fromName,
    ) {
        $this->id = Id::generate();
        $this->change($host, $port, $encryption, $username, $sealedSecret, $fromAddress, $fromName);
    }

    public static function define(LegalEntity $firm, string $host, int $port, MailEncryption $encryption, string $username, string $sealedSecret, string $fromAddress, string $fromName): self
    {
        return new self($firm, $host, $port, $encryption, $username, $sealedSecret, $fromAddress, $fromName);
    }

    /**
     * Whether these connection settings differ from the stored ones, so the
     * stored secret must not go along: it would reach another server or account.
     */
    public function needsSecretFor(string $host, int $port, MailEncryption $encryption, string $username): bool
    {
        return (self::normalHost($host) ?? $host) !== $this->host
            || $port !== $this->port
            || $encryption !== $this->encryption
            || trim($username) !== $this->username;
    }

    /**
     * A null secret keeps the stored one, so a form need not show it again, but
     * only while the host, port, encryption and user name stay the same. Any
     * change asks for a fresh check of the connection.
     */
    public function change(string $host, int $port, MailEncryption $encryption, string $username, ?string $sealedSecret, string $fromAddress, string $fromName): void
    {
        $host = self::normalHost($host);
        if (null === $host) {
            throw new InvalidMailServer('The mail server needs a host name or an IP address, without a port.');
        }
        if (!\in_array($port, self::PORTS, true)) {
            throw new InvalidMailServer(\sprintf('The mail server port must be one of %s.', implode(', ', self::PORTS)));
        }
        $username = trim($username);
        $fromAddress = trim($fromAddress);
        $fromName = trim($fromName);
        foreach ([$username, $fromAddress, $fromName, $sealedSecret ?? ''] as $value) {
            if (1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidMailServer('A mail server value cannot hold a line break or a control character.');
            }
        }
        if (false === filter_var($fromAddress, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidMailServer('The sender needs a valid e-mail address.');
        }
        if ('' === $sealedSecret) {
            throw new InvalidMailServer('The mail server needs a password.');
        }
        if (null === $sealedSecret && $this->needsSecretFor($host, $port, $encryption, $username)) {
            throw new InvalidMailServer('A new host, port, encryption or user name needs the password again.');
        }

        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->username = $username;
        $this->sealedSecret = $sealedSecret ?? $this->sealedSecret;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName;
        $this->verifiedAt = null;
        $this->lastTestAt = null;
        $this->lastTestError = null;
    }

    /**
     * A test message went through with these settings.
     */
    public function markVerified(\DateTimeImmutable $at): void
    {
        $this->verifiedAt = $at;
        $this->lastTestAt = $at;
        $this->lastTestError = null;
    }

    /**
     * A test message was refused. A settings problem (a refused login, a failed
     * secure connection, a host not allowed) means these settings no longer
     * count as verified; a refusal of another kind leaves an earlier pass as it is.
     */
    public function markTestFailed(\DateTimeImmutable $at, string $safeReason, bool $settingsProblem): void
    {
        $this->lastTestAt = $at;
        $this->lastTestError = mb_substr($safeReason, 0, 255);
        if ($settingsProblem) {
            $this->verifiedAt = null;
        }
    }

    public function lastTestAt(): ?\DateTimeImmutable
    {
        return $this->lastTestAt;
    }

    public function lastTestError(): ?string
    {
        return $this->lastTestError;
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

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function encryption(): MailEncryption
    {
        return $this->encryption;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function sealedSecret(): string
    {
        return $this->sealedSecret;
    }

    public function fromAddress(): string
    {
        return $this->fromAddress;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    public function verifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    /**
     * The host in its stored form: a lower-case name, an IPv4 address, or an IPv6
     * address in brackets, as in a URL; null when it is none of these.
     */
    public static function normalHost(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $literal = trim($host, '[]');
        if (false !== filter_var($literal, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return '['.$literal.']';
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) || 1 === preg_match(self::HOST_NAME, $host)) {
            return $host;
        }

        return null;
    }
}
