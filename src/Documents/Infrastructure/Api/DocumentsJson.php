<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api;

use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\LinkTargets;
use Foresi\Documents\Application\TemplateRenderer;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * The JSON of Documents, the same over REST and MCP.
 */
final readonly class DocumentsJson
{
    public function __construct(
        private DocumentsStore $store,
        private LinkTargets $targets,
        private DocumentVariables $variables,
        private TemplateRenderer $renderer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function type(DocumentType $type): array
    {
        return [
            'id' => $type->id(),
            'name' => $type->name(),
            'code' => $type->code(),
            'needs_signing' => $type->needsSigning(),
            'signature_level' => $type->signatureLevel()->value,
            'archived' => $type->isArchived(),
        ];
    }

    /**
     * The template with its latest version and the variables it can use.
     *
     * @return array<string, mixed>
     */
    public function template(DocumentTemplate $template): array
    {
        $versions = $this->store->versions($template->id());
        $latest = $versions[0] ?? null;
        $variables = [];
        foreach ($this->variables->definitions() as $prefix => $definitions) {
            foreach ($definitions as $definition) {
                $variables[] = ['name' => $prefix.'.'.$definition->name, 'label' => $definition->label, 'example' => $definition->example, 'image' => $definition->image];
            }
        }

        return [
            'id' => $template->id(),
            'type_id' => $template->typeId(),
            'name' => $template->name(),
            'archived' => $template->isArchived(),
            'version' => null === $latest ? null : $this->version($latest),
            'versions' => array_map(static fn (TemplateVersion $version): array => ['id' => $version->id(), 'number' => $version->number(), 'frozen' => $version->isFrozen()], $versions),
            'variables' => $variables,
        ];
    }

    /**
     * A template in a list: its latest version without the body.
     *
     * @return array<string, mixed>
     */
    public function templateRow(DocumentTemplate $template): array
    {
        $latest = $this->store->latestVersion($template->id());

        return [
            'id' => $template->id(),
            'type_id' => $template->typeId(),
            'name' => $template->name(),
            'archived' => $template->isArchived(),
            'version' => $latest instanceof TemplateVersion ? ['id' => $latest->id(), 'number' => $latest->number(), 'frozen' => $latest->isFrozen()] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function version(TemplateVersion $version): array
    {
        $page = $version->page();

        return [
            'id' => $version->id(),
            'number' => $version->number(),
            'body' => $version->body(),
            'frozen' => $version->isFrozen(),
            'page' => [
                'paper' => $page->paper->value,
                'landscape' => $page->landscape,
                'margin_top' => $page->marginTopMm,
                'margin_right' => $page->marginRightMm,
                'margin_bottom' => $page->marginBottomMm,
                'margin_left' => $page->marginLeftMm,
            ],
            'uses' => $this->renderer->variables($version->body()),
        ];
    }

    /**
     * A document in a list: no values, no signers.
     *
     * @return array<string, mixed>
     */
    public function documentRow(Document $document): array
    {
        $type = $this->store->type($document->typeId());

        return [
            'id' => $document->id(),
            'title' => $document->title(),
            'status' => $document->status()->value,
            'type' => ['id' => $document->typeId(), 'code' => $type?->code(), 'name' => $type?->name()],
            'created_at' => JsonValues::timestamp($document->createdAt()),
            'generated_at' => JsonValues::timestamp($document->generatedAt()),
            'completed_at' => JsonValues::timestamp($document->completedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Document $document): array
    {
        $version = null === $document->templateVersionId() ? null : $this->store->version($document->templateVersionId());

        return $this->documentRow($document) + [
            'template' => ['id' => $document->templateId(), 'version_id' => $document->templateVersionId(), 'version_number' => $version?->number()],
            'links' => array_map(function (\Foresi\Documents\Domain\DocumentLink $link): array {
                $view = $this->targets->describe(new RecordLink($link->kind(), $link->targetId()));

                return ['kind' => $view->kind, 'id' => $view->id, 'available' => $view->available, 'label' => $view->label, 'url' => $view->url];
            }, $this->store->links($document)),
            'signers' => array_map($this->signer(...), $this->store->signers($document)),
            'files' => [
                'pdf' => $this->file($document->pdfAttachmentId(), $document->pdfSha256()),
                'signed' => $this->file($document->signedAttachmentId(), $document->signedSha256()),
                'certificate' => $this->file($document->certificateAttachmentId(), $document->certificateSha256()),
            ],
            'values' => $document->resolvedValues(),
            'provider' => $document->provider(),
            'envelope_id' => $document->envelopeId(),
            'sent_at' => JsonValues::timestamp($document->sentAt()),
            'closed_at' => JsonValues::timestamp($document->closedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function signer(Signer $signer): array
    {
        return [
            'id' => $signer->id(),
            'name' => $signer->name(),
            'email' => $signer->email(),
            'party_person_id' => $signer->partyPersonId(),
            'side' => $signer->side()->value,
            'order' => $signer->order(),
            'status' => $signer->status()->value,
            'signed_at' => JsonValues::timestamp($signer->signedAt()),
            'method' => $signer->method(),
        ];
    }

    /**
     * @return array{attachment_id: string, sha256: ?string}|null
     */
    private function file(?string $attachmentId, ?string $sha256): ?array
    {
        return null === $attachmentId ? null : ['attachment_id' => $attachmentId, 'sha256' => $sha256];
    }
}
