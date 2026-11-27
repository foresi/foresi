<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Infrastructure\Doctrine\RunsOnlyForReal;
use Foresi\Shared\Infrastructure\Migration\ModuleRename;
use Foresi\Shared\Infrastructure\Migration\RenamesTables;

/**
 * The stored names of the documents take the module's id, document-templates:
 * the tables are named after their records (documents_document becomes
 * document, documents_template document_template, …) with their indexes and
 * foreign keys, and the events (documents.document.created becomes
 * document-templates.document.created) in the event log, the webhook
 * deliveries and the endpoints' subscriptions (documents.* becomes
 * document-templates.*). The rows stay. Going down names them the old way again.
 */
final class Version20261127150000 extends AbstractMigration implements RenamesTables, RunsOnlyForReal
{
    private const array TABLES = [
        'documents_type' => 'document_type',
        'documents_template' => 'document_template',
        'documents_template_version' => 'document_template_version',
        'documents_document' => 'document',
        'documents_document_link' => 'document_link',
        'documents_signer' => 'document_signer',
    ];

    /** Each index by its old name: the table it is on (its new name), its new name, its columns, whether it is unique. */
    private const array INDEXES = [
        'uniq_documents_type_code' => ['document_type', 'uniq_document_type_code', ['code'], true],
        'idx_documents_template_type' => ['document_template', 'idx_document_template_type', ['type_id'], false],
        'uniq_documents_template_version_number' => ['document_template_version', 'uniq_document_template_version_number', ['template_id', 'number'], true],
        'idx_documents_document_type' => ['document', 'idx_document_type', ['type_id'], false],
        'idx_documents_document_status' => ['document', 'idx_document_status', ['status'], false],
        'idx_documents_document_link_target' => ['document_link', 'idx_document_link_target', ['kind', 'target_id'], false],
        // The index PostgreSQL got for the foreign key to the document, named by Doctrine after the table and the column.
        'idx_8935373ac33f7837' => ['document_link', 'idx_91181562c33f7837', ['document_id'], false],
        'idx_documents_signer_document' => ['document_signer', 'idx_document_signer_document', ['document_id'], false],
    ];

    /** Each foreign key by its old name: the table it is on (its new name) and its new name. */
    private const array FOREIGN_KEYS = [
        'fk_documents_document_link_document' => ['document_link', 'fk_document_link_document'],
        'fk_documents_signer_document' => ['document_signer', 'fk_document_signer_document'],
    ];

    public function getDescription(): string
    {
        return 'Name the tables and events of documents after their records and the module document-templates';
    }

    public function renamedTables(): array
    {
        return self::TABLES;
    }

    public function up(Schema $schema): void
    {
        // The rows stay: postUp() renames in place what a schema change would drop and create.
    }

    public function postUp(Schema $schema): void
    {
        $rename = new ModuleRename($this->connection);
        $rename->renameTables(self::TABLES, self::INDEXES, self::FOREIGN_KEYS);
        $rename->renameEvents('documents.', 'document-templates.');
    }

    public function down(Schema $schema): void
    {
        // The rows change in postDown().
    }

    public function postDown(Schema $schema): void
    {
        $rename = new ModuleRename($this->connection);
        $rename->renameEvents('document-templates.', 'documents.');
        $rename->renameTablesBack(self::TABLES, self::INDEXES, self::FOREIGN_KEYS);
    }
}
