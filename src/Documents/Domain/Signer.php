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
 * Someone who signs a document, for the firm or for the other party. The order
 * is shown, not enforced: signers of one order sign in parallel.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_signer')]
#[ORM\Index(name: 'idx_documents_signer_document', columns: ['document_id'])]
class Signer implements Audited
{
    public const int MAX_NAME = 160;
    public const int MAX_EMAIL = 254;
    public const string MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_NAME)]
    private string $name;

    #[ORM\Column(type: 'string', length: self::MAX_EMAIL, nullable: true)]
    private ?string $email;

    /** The person of a contact this signer is, when known. */
    #[ORM\Column(name: 'party_person_id', type: 'guid', nullable: true)]
    private ?string $partyPersonId;

    #[ORM\Column(type: 'string', length: 8, enumType: SignerSide::class)]
    private SignerSide $side;

    #[ORM\Column(name: 'sign_order', type: 'integer')]
    private int $order;

    #[ORM\Column(type: 'string', length: 16, enumType: SignerStatus::class)]
    private SignerStatus $status = SignerStatus::Pending;

    #[ORM\Column(name: 'signed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    /** "manual", or the code of the signature provider that recorded the signature. */
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $method = null;

    public function __construct(#[ORM\ManyToOne(targetEntity: Document::class)]
        #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
        private Document $document, string $name, ?string $email, ?string $partyPersonId, SignerSide $side, int $order)
    {
        $this->id = Id::generate();
        $this->change($name, $email, $partyPersonId, $side, $order);
    }

    public function change(string $name, ?string $email, ?string $partyPersonId, SignerSide $side, int $order): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidField('name', \sprintf('A signer\'s name has 1 to %d characters.', self::MAX_NAME));
        }
        $email = null === $email || '' === trim($email) ? null : trim($email);
        // filter_var() also refuses an address longer than MAX_EMAIL.
        if (null !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidField('email', 'The signer\'s e-mail is not a valid address.');
        }
        if ($order < 1 || $order > 99) {
            throw new InvalidField('order', 'A signing order is between 1 and 99.');
        }
        if (null !== $partyPersonId && !Id::isValid($partyPersonId)) {
            throw new InvalidField('partyPersonId', 'The person id is not a valid id.');
        }
        $this->name = $name;
        $this->email = $email;
        $this->partyPersonId = $partyPersonId;
        $this->side = $side;
        $this->order = $order;
    }

    public function sign(\DateTimeImmutable $at, string $method): void
    {
        $this->status = SignerStatus::Signed;
        $this->signedAt = $at;
        $this->method = $method;
    }

    public function decline(): void
    {
        $this->status = SignerStatus::Declined;
    }

    /** As pending again, for a new draft made from a final document. */
    public function copyTo(Document $document): self
    {
        return new self($document, $this->name, $this->email, $this->partyPersonId, $this->side, $this->order);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function documentId(): string
    {
        return $this->document->id();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function partyPersonId(): ?string
    {
        return $this->partyPersonId;
    }

    public function side(): SignerSide
    {
        return $this->side;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function status(): SignerStatus
    {
        return $this->status;
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function method(): ?string
    {
        return $this->method;
    }
}
