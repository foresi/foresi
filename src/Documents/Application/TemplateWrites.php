<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Application\Api\ArchiveDocumentTemplateInput;
use Foresi\Documents\Application\Api\CreateDocumentTemplateInput;
use Foresi\Documents\Application\Api\SaveTemplateVersionInput;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Domain\PaperSize;
use Foresi\Documents\Domain\TemplateVersion;
use Psr\Clock\ClockInterface;

final readonly class TemplateWrites
{
    public function __construct(
        private DocumentsAccess $access,
        private DocumentsStore $store,
        private DocumentsTransaction $transaction,
        private TemplateRenderer $renderer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DocumentsRefused
     */
    public function create(CreateDocumentTemplateInput $input): TemplateVersion
    {
        $this->access->denyUnlessStaff();
        $page = $this->page($input->paper, $input->landscape, $input->marginTop, $input->marginRight, $input->marginBottom, $input->marginLeft);
        $this->renderer->check($input->body);

        return $this->transaction->run(function () use ($input, $page): TemplateVersion {
            $type = $this->store->type($input->typeId);
            if (!$type instanceof DocumentType || $type->isArchived()) {
                throw DocumentsRefused::invalid('type_unknown', 'typeId', 'There is no active document type with this id. List them with foresi_list_document_types.');
            }
            $now = $this->clock->now();
            $template = DocumentsInvalid::unless('name', static fn (): DocumentTemplate => new DocumentTemplate($type, $input->name, $now));
            $version = DocumentsInvalid::unless('body', static fn (): TemplateVersion => new TemplateVersion($template, 1, $input->body, $page, $now));
            $this->store->add($template);
            $this->store->add($version);

            return $version;
        });
    }

    /**
     * Changes the latest version in place until a document is generated from it;
     * a frozen version is kept and the body becomes the next version.
     *
     * @throws DocumentsRefused
     */
    public function saveVersion(SaveTemplateVersionInput $input): TemplateVersion
    {
        $this->access->denyUnlessStaff();
        $page = $this->page($input->paper, $input->landscape, $input->marginTop, $input->marginRight, $input->marginBottom, $input->marginLeft);
        $this->renderer->check($input->body);

        return $this->transaction->run(function () use ($input, $page): TemplateVersion {
            $template = $this->template($input->templateId);
            $latest = $this->store->latestVersion($template->id()) ?? throw new \LogicException('A template has at least one version.');
            if (!$latest->isFrozen()) {
                DocumentsInvalid::unless('body', static fn () => $latest->edit($input->body, $page));

                return $latest;
            }
            $next = DocumentsInvalid::unless('body', fn (): TemplateVersion => new TemplateVersion($template, $latest->number() + 1, $input->body, $page, $this->clock->now()));
            $this->store->add($next);

            return $next;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function archive(ArchiveDocumentTemplateInput $input): DocumentTemplate
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): DocumentTemplate {
            $template = $this->template($input->templateId);
            $template->archive($this->clock->now());

            return $template;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    private function page(string $paper, bool $landscape, float $top, float $right, float $bottom, float $left): PageSettings
    {
        $size = PaperSize::tryFrom($paper) ?? throw DocumentsRefused::invalid('paper_unknown', 'paper', 'The paper is a4 or letter.');

        return DocumentsInvalid::unless('marginTop', static fn (): PageSettings => new PageSettings($size, $landscape, $top, $right, $bottom, $left));
    }

    /**
     * @throws DocumentsRefused
     */
    private function template(string $id): DocumentTemplate
    {
        return $this->store->template($id) ?? throw DocumentsRefused::notFound('There is no document template with this id. List them with foresi_list_document_templates.');
    }
}
