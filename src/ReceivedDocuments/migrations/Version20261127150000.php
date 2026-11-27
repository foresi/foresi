<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Infrastructure\Doctrine\RunsOnlyForReal;
use Foresi\Shared\Infrastructure\Migration\ModuleRename;
use Foresi\Shared\Infrastructure\Migration\RenamesTables;

/**
 * The stored names of the received documents take the module's id,
 * received-documents: the two tables still named after purchasing
 * (purchasing_document_reading becomes received_document_reading,
 * purchasing_mail_intake received_document_mail_intake) with their index and
 * foreign key, the events (purchasing.received_document.recorded becomes
 * received-documents.received_document.recorded) in the event log, the webhook
 * deliveries and the endpoints' subscriptions (purchasing.* becomes
 * received-documents.*), and the key of the list in the saved views
 * (purchasing.received becomes received_documents.received). The rows stay.
 * Going down names them the old way again.
 */
final class Version20261127150000 extends AbstractMigration implements RenamesTables, RunsOnlyForReal
{
    private const array TABLES = [
        'purchasing_document_reading' => 'received_document_reading',
        'purchasing_mail_intake' => 'received_document_mail_intake',
    ];

    /** Each index by its old name: the table it is on (its new name), its new name, its columns, whether it is unique. */
    private const array INDEXES = [
        'idx_purchasing_mail_intake_owner' => ['received_document_mail_intake', 'idx_received_document_mail_intake_owner', ['owner_user_id', 'created_at'], false],
    ];

    /** Each foreign key by its old name: the table it is on (its new name) and its new name. */
    private const array FOREIGN_KEYS = [
        'fk_purchasing_document_reading_document' => ['received_document_reading', 'fk_received_document_reading_document'],
    ];

    private const array LISTS = [
        'purchasing.received' => 'received_documents.received',
    ];

    public function getDescription(): string
    {
        return 'Name the tables, events and list of received documents after the module received-documents';
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
        $rename->renameEvents('purchasing.', 'received-documents.');
        $rename->renameListKeys(self::LISTS);
    }

    public function down(Schema $schema): void
    {
        // The rows change in postDown().
    }

    public function postDown(Schema $schema): void
    {
        $rename = new ModuleRename($this->connection);
        $rename->renameListKeys(array_flip(self::LISTS));
        $rename->renameEvents('received-documents.', 'purchasing.');
        $rename->renameTablesBack(self::TABLES, self::INDEXES, self::FOREIGN_KEYS);
    }
}
