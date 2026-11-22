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
 * One version of a template: its HTML body and its paper. A version is edited in
 * place until a document is generated from it; from then on it is frozen, and
 * saving the template makes the next version.
 */
#[ORM\Entity]
#[ORM\Table(name: 'documents_template_version')]
#[ORM\UniqueConstraint(name: 'uniq_documents_template_version_number', columns: ['template_id', 'number'])]
class TemplateVersion implements Audited
{
    /** The longest body, in bytes. */
    public const int MAX_BODY = 200_000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'template_id', type: 'guid')]
    private string $templateId;

    #[ORM\Column(type: 'integer')]
    private int $number;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'string', length: 8, enumType: PaperSize::class)]
    private PaperSize $paper;

    #[ORM\Column(type: 'boolean')]
    private bool $landscape;

    #[ORM\Column(name: 'margin_top_mm', type: 'float')]
    private float $marginTopMm;

    #[ORM\Column(name: 'margin_right_mm', type: 'float')]
    private float $marginRightMm;

    #[ORM\Column(name: 'margin_bottom_mm', type: 'float')]
    private float $marginBottomMm;

    #[ORM\Column(name: 'margin_left_mm', type: 'float')]
    private float $marginLeftMm;

    #[ORM\Column(name: 'frozen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $frozenAt = null;

    public function __construct(DocumentTemplate $template, int $number, string $body, PageSettings $page, #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt)
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Template versions are numbered from 1.');
        }
        $this->id = Id::generate();
        $this->templateId = $template->id();
        $this->number = $number;
        $this->write($body, $page);
    }

    /**
     * @throws \LogicException when the version is frozen
     */
    public function edit(string $body, PageSettings $page): void
    {
        if ($this->isFrozen()) {
            throw new \LogicException('A frozen template version cannot be edited; save a new version.');
        }
        $this->write($body, $page);
    }

    public function freeze(\DateTimeImmutable $now): void
    {
        $this->frozenAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function templateId(): string
    {
        return $this->templateId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function page(): PageSettings
    {
        return new PageSettings($this->paper, $this->landscape, $this->marginTopMm, $this->marginRightMm, $this->marginBottomMm, $this->marginLeftMm);
    }

    public function isFrozen(): bool
    {
        return $this->frozenAt instanceof \DateTimeImmutable;
    }

    public function frozenAt(): ?\DateTimeImmutable
    {
        return $this->frozenAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function write(string $body, PageSettings $page): void
    {
        if ('' === trim($body) || \strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException(\sprintf('A template body has 1 to %d bytes.', self::MAX_BODY));
        }
        $this->body = $body;
        $this->paper = $page->paper;
        $this->landscape = $page->landscape;
        $this->marginTopMm = $page->marginTopMm;
        $this->marginRightMm = $page->marginRightMm;
        $this->marginBottomMm = $page->marginBottomMm;
        $this->marginLeftMm = $page->marginLeftMm;
    }
}
