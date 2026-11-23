<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * A document made from a template for some records: a draft, then generated
 * into a frozen PDF with a snapshot of its values, signed by its signers and
 * completed once every signer signed and the signed copy is stored.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_document')]
#[ORM\Index(name: 'idx_documents_document_type', columns: ['type_id'])]
#[ORM\Index(name: 'idx_documents_document_status', columns: ['status'])]
class Document implements Audited
{
    public const int MAX_TITLE = 200;
    public const string ATTACHMENT_KIND = 'document';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'type_id', type: 'guid')]
    private string $typeId;

    #[ORM\Column(name: 'template_id', type: 'guid')]
    private string $templateId;

    /** The version it was generated from; null while a draft. */
    #[ORM\Column(name: 'template_version_id', type: 'guid', nullable: true)]
    private ?string $templateVersionId = null;

    #[ORM\Column(type: 'string', length: self::MAX_TITLE)]
    private string $title;

    #[ORM\Column(type: 'string', length: 24, enumType: DocumentStatus::class)]
    private DocumentStatus $status = DocumentStatus::Draft;

    /**
     * The values the template was filled with, as they were when it was generated.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'resolved_values', type: 'json', nullable: true)]
    private ?array $resolvedValues = null;

    #[ORM\Column(name: 'pdf_attachment_id', type: 'guid', nullable: true)]
    private ?string $pdfAttachmentId = null;

    #[ORM\Column(name: 'pdf_sha256', type: 'string', length: 64, nullable: true)]
    private ?string $pdfSha256 = null;

    #[ORM\Column(name: 'signed_attachment_id', type: 'guid', nullable: true)]
    private ?string $signedAttachmentId = null;

    #[ORM\Column(name: 'signed_sha256', type: 'string', length: 64, nullable: true)]
    private ?string $signedSha256 = null;

    /** A signature provider's certificate; always null while signing is manual. */
    #[ORM\Column(name: 'certificate_attachment_id', type: 'guid', nullable: true)]
    private ?string $certificateAttachmentId = null;

    #[ORM\Column(name: 'certificate_sha256', type: 'string', length: 64, nullable: true)]
    private ?string $certificateSha256 = null;

    /** The signature provider that sent it; null for manual signing. */
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(name: 'envelope_id', type: 'string', length: 128, nullable: true)]
    private ?string $envelopeId = null;

    #[ORM\Column(name: 'generated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** When a cancelled document went to the trash; it is kept there for its history. */
    #[ORM\Column(name: 'trashed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    /** Who put it in the trash. */
    #[ORM\Column(name: 'trashed_by', type: 'guid', nullable: true)]
    private ?string $trashedBy = null;

    /**
     * Its links and signers are records of their own (DocumentLink, Signer), kept
     * by the store, since changes to collections of audited entities are not audited.
     */
    public function __construct(DocumentType $type, DocumentTemplate $template, string $title, #[ORM\Column(name: 'created_by', type: 'guid')]
        private string $createdBy, #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt)
    {
        if ($template->typeId() !== $type->id()) {
            throw new \InvalidArgumentException('The template is of another type.');
        }
        $this->id = Id::generate();
        $this->typeId = $type->id();
        $this->templateId = $template->id();
        $this->retitle($title);
    }

    /**
     * A new draft of a final or cancelled document: the same type, template and
     * title. The caller copies its links and signers. It is generated from the
     * template's latest version.
     */
    public function again(string $createdBy, \DateTimeImmutable $now): self
    {
        if (!$this->status->isFinal()) {
            throw new DocumentConflict('not_final', 'Only a completed, declined or cancelled document can be generated again; this one can still change.');
        }
        $this->requireNotTrashed();
        $copy = clone $this;
        $copy->id = Id::generate();
        $copy->status = DocumentStatus::Draft;
        $copy->templateVersionId = null;
        $copy->resolvedValues = null;
        $copy->pdfAttachmentId = $copy->pdfSha256 = $copy->signedAttachmentId = $copy->signedSha256 = null;
        $copy->certificateAttachmentId = $copy->certificateSha256 = $copy->provider = $copy->envelopeId = null;
        $copy->generatedAt = $copy->sentAt = $copy->completedAt = $copy->closedAt = null;
        $copy->trashedAt = null;
        $copy->trashedBy = null;
        $copy->createdBy = $createdBy;
        $copy->createdAt = $now;

        return $copy;
    }

    public function retitle(string $title): void
    {
        $this->requireDraft();
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > self::MAX_TITLE) {
            throw new \InvalidArgumentException(\sprintf('A document title has 1 to %d characters.', self::MAX_TITLE));
        }
        $this->title = $title;
    }

    /**
     * @throws DocumentConflict when the document is no longer a draft
     */
    public function checkLinksEditable(): void
    {
        $this->requireDraft();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function generate(TemplateVersion $version, array $values, string $pdfAttachmentId, string $pdfSha256, \DateTimeImmutable $now): void
    {
        $this->requireDraft();
        if ($version->templateId() !== $this->templateId) {
            throw new \InvalidArgumentException('The version is of another template.');
        }
        $version->freeze($now);
        $this->templateVersionId = $version->id();
        $this->resolvedValues = $values;
        $this->pdfAttachmentId = $pdfAttachmentId;
        $this->pdfSha256 = $pdfSha256;
        $this->generatedAt = $now;
        $this->status = DocumentStatus::Ready;
    }

    /**
     * @return bool whether it went out now; false when it already was
     */
    public function markSent(\DateTimeImmutable $now): bool
    {
        if (DocumentStatus::OutForSignature === $this->status) {
            return false;
        }
        if (DocumentStatus::Ready !== $this->status) {
            throw new DocumentConflict('not_ready', 'Only a generated document that is ready can go out for signature.');
        }
        $this->status = DocumentStatus::OutForSignature;
        $this->sentAt = $now;

        return true;
    }

    /**
     * Stores the signed copy. From a ready document this also means it was sent.
     *
     * @return bool whether it went out for signature now
     */
    public function attachSignedCopy(string $attachmentId, string $sha256, \DateTimeImmutable $now): bool
    {
        $this->checkSignedCopy();
        $sent = $this->markSent($now);
        $this->signedAttachmentId = $attachmentId;
        $this->signedSha256 = $sha256;

        return $sent;
    }

    /**
     * @throws DocumentConflict when the document cannot take its signed copy now
     */
    public function checkSignedCopy(): void
    {
        $this->requireSigning();
        if (null !== $this->signedAttachmentId) {
            throw new DocumentConflict('signed_copy_exists', 'The document already has its signed copy.');
        }
    }

    /**
     * @throws DocumentConflict when the signers can no longer change
     */
    public function checkSignersEditable(): void
    {
        $this->requireOpen();
    }

    /**
     * Marks a signer signed. From a ready document this also means it was sent.
     *
     * @return bool whether it went out for signature now
     */
    public function markSigned(Signer $signer, \DateTimeImmutable $at, string $method): bool
    {
        $this->requireSigning();
        $this->own($signer);
        if (SignerStatus::Pending !== $signer->status()) {
            throw new DocumentConflict('signer_not_pending', 'This signer already signed or declined.');
        }
        $sent = $this->markSent($at);
        $signer->sign($at, $method);

        return $sent;
    }

    public function markDeclined(Signer $signer, \DateTimeImmutable $now): void
    {
        $this->requireSigning();
        $this->own($signer);
        if (SignerStatus::Pending !== $signer->status()) {
            throw new DocumentConflict('signer_not_pending', 'This signer already signed or declined.');
        }
        $signer->decline();
        $this->status = DocumentStatus::Declined;
        $this->closedAt = $now;
    }

    /**
     * Completes the document when every signer signed and the signed copy is
     * stored. Called after each of those writes, in either order.
     *
     * @param list<Signer> $signers all signers of the document
     *
     * @return bool whether it was completed now; false when it is not done, or already was
     */
    public function completeIfDone(array $signers, \DateTimeImmutable $now): bool
    {
        if (DocumentStatus::OutForSignature !== $this->status || null === $this->signedAttachmentId) {
            return false;
        }
        foreach ($signers as $signer) {
            $this->own($signer);
            if (SignerStatus::Signed !== $signer->status()) {
                return false;
            }
        }
        $this->status = DocumentStatus::Completed;
        $this->completedAt = $now;
        $this->closedAt = $now;

        return true;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if ($this->status->isFinal()) {
            throw new DocumentConflict('final', 'A completed, declined or cancelled document cannot be cancelled.');
        }
        $this->status = DocumentStatus::Cancelled;
        $this->closedAt = $now;
    }

    /**
     * Puts a cancelled document in the trash: it leaves the lists and keeps its
     * history, files and template version. Trashing it again changes nothing.
     *
     * @return bool whether it went to the trash now
     *
     * @throws DocumentConflict when the document is not cancelled
     */
    public function trash(string $userId, \DateTimeImmutable $now): bool
    {
        if ($this->trashedAt instanceof \DateTimeImmutable) {
            return false;
        }
        if (DocumentStatus::Cancelled !== $this->status) {
            throw new DocumentConflict('not_cancelled', 'Only a cancelled document goes to the trash; cancel it first.');
        }
        $this->trashedAt = $now;
        $this->trashedBy = $userId;

        return true;
    }

    /**
     * Brings it back from the trash; one that is not there changes nothing.
     *
     * @return bool whether it was in the trash
     */
    public function restore(): bool
    {
        if (!$this->trashedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->trashedAt = null;
        $this->trashedBy = null;

        return true;
    }

    private function own(Signer $signer): void
    {
        if ($signer->documentId() !== $this->id) {
            throw new DocumentConflict('signer_unknown', 'The document has no signer with this id.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function typeId(): string
    {
        return $this->typeId;
    }

    public function templateId(): string
    {
        return $this->templateId;
    }

    public function templateVersionId(): ?string
    {
        return $this->templateVersionId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolvedValues(): ?array
    {
        return $this->resolvedValues;
    }

    public function pdfAttachmentId(): ?string
    {
        return $this->pdfAttachmentId;
    }

    public function pdfSha256(): ?string
    {
        return $this->pdfSha256;
    }

    public function signedAttachmentId(): ?string
    {
        return $this->signedAttachmentId;
    }

    public function signedSha256(): ?string
    {
        return $this->signedSha256;
    }

    public function certificateAttachmentId(): ?string
    {
        return $this->certificateAttachmentId;
    }

    public function certificateSha256(): ?string
    {
        return $this->certificateSha256;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function envelopeId(): ?string
    {
        return $this->envelopeId;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function generatedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isTrashed(): bool
    {
        return $this->trashedAt instanceof \DateTimeImmutable;
    }

    public function trashedAt(): ?\DateTimeImmutable
    {
        return $this->trashedAt;
    }

    public function trashedBy(): ?string
    {
        return $this->trashedBy;
    }

    private function requireDraft(): void
    {
        if (DocumentStatus::Draft !== $this->status) {
            throw new DocumentConflict('not_draft', 'Only a draft can be edited; a generated document is frozen. Cancel it and generate it again instead.');
        }
    }

    private function requireNotTrashed(): void
    {
        if ($this->trashedAt instanceof \DateTimeImmutable) {
            throw new DocumentConflict('trashed', 'The document is in the trash; restore it first.');
        }
    }

    /** Signers change until the document is completed, declined or cancelled. */
    private function requireOpen(): void
    {
        if ($this->status->isFinal()) {
            throw new DocumentConflict('final', 'The signers of a completed, declined or cancelled document cannot change.');
        }
    }

    /** Signatures are recorded on a generated document that is not final. */
    private function requireSigning(): void
    {
        if (!\in_array($this->status, [DocumentStatus::Ready, DocumentStatus::OutForSignature], true)) {
            throw new DocumentConflict('not_signable', 'Signatures are recorded on a generated document that is ready or out for signature.');
        }
    }
}
