<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Application\Api\ArchiveDocumentTypeInput;
use Foresi\Documents\Application\Api\CreateDocumentTypeInput;
use Foresi\Documents\Application\Api\UpdateDocumentTypeInput;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\SignatureLevel;
use Psr\Clock\ClockInterface;

final readonly class DocumentTypeWrites
{
    public function __construct(
        private DocumentsAccess $access,
        private DocumentsStore $store,
        private DocumentsTransaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DocumentsRefused
     */
    public function create(CreateDocumentTypeInput $input): DocumentType
    {
        $this->access->denyUnlessStaff();
        $level = $this->level($input->signatureLevel);

        return $this->transaction->run(function () use ($input, $level): DocumentType {
            if ($this->store->typeByCode($input->code) instanceof DocumentType) {
                throw DocumentsRefused::invalid('code_taken', 'code', 'Another document type has this code; codes are unique.');
            }
            $type = DocumentsInvalid::unless('name', fn (): DocumentType => new DocumentType($input->name, $input->code, $input->needsSigning, $level, $this->clock->now()));
            $this->store->add($type);

            return $type;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function update(UpdateDocumentTypeInput $input): DocumentType
    {
        $level = $this->level($input->signatureLevel);

        return $this->transaction->run(function () use ($input, $level): DocumentType {
            $type = $this->type($input->typeId);
            DocumentsInvalid::unless('name', static fn () => $type->change($input->name, $input->needsSigning, $level));

            return $type;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    public function archive(ArchiveDocumentTypeInput $input): DocumentType
    {
        return $this->transaction->run(function () use ($input): DocumentType {
            $type = $this->type($input->typeId);
            $type->archive($this->clock->now());

            return $type;
        });
    }

    /**
     * @throws DocumentsRefused
     */
    private function type(string $id): DocumentType
    {
        $this->access->denyUnlessStaff();

        return $this->store->type($id) ?? throw DocumentsRefused::notFound('There is no document type with this id. List them with foresi_list_document_types.');
    }

    private function level(string $level): SignatureLevel
    {
        return SignatureLevel::tryFrom($level) ?? throw DocumentsRefused::invalid('signature_level_unknown', 'signatureLevel', 'The signature level is simple, advanced or qualified.');
    }
}
