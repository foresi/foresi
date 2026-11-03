<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A firm's own subject and body for one kind of mail in one document language.
 * The text holds variables as tokens such as {number}; which ones exist is known
 * to the application, which refuses an unknown one before it gets here. A firm
 * without a row sends the built-in text.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mail_template')]
#[ORM\Index(name: 'idx_mail_template_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_mail_template_firm_kind_language', columns: ['legal_entity_id', 'kind', 'language'])]
class MailTemplate implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_SUBJECT = 255;
    public const int MAX_BODY = 10000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_SUBJECT)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $body;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(type: 'string', length: 16, enumType: MailTemplateKind::class)]
        private readonly MailTemplateKind $kind,
        #[ORM\Column(type: 'string', length: 8, enumType: DocumentLanguage::class)]
        private readonly DocumentLanguage $language,
        string $subject,
        string $body,
    ) {
        $this->id = Id::generate();
        $this->change($subject, $body);
    }

    public static function define(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): self
    {
        return new self($firm, $kind, $language, $subject, $body);
    }

    /**
     * The subject is one line: a line break in it could start a new mail header.
     */
    public function change(string $subject, string $body): void
    {
        $subject = trim($subject);
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ('' === $subject || '' === $body) {
            throw new InvalidMailTemplate('A mail template needs a subject and a body.');
        }
        if (1 === preg_match('/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]/u', $subject)) {
            throw new InvalidMailTemplate('The subject of a mail is one line.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $body)) {
            throw new InvalidMailTemplate('The body of a mail cannot hold a control character.');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            throw new InvalidMailTemplate(\sprintf('A subject takes up to %d characters and a body up to %d.', self::MAX_SUBJECT, self::MAX_BODY));
        }

        $this->subject = $subject;
        $this->body = $body;
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

    public function kind(): MailTemplateKind
    {
        return $this->kind;
    }

    public function language(): DocumentLanguage
    {
        return $this->language;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
