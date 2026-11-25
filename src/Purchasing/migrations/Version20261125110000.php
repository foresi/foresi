<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The mails taken in by the capture addresses (Mail's inbound addresses of the
 * purpose received_documents), one row each with what came of
 * it, and the mail a draft was filed from. One firm per instance (ADR 0039), so
 * no firm id.
 */
final class Version20261125110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mail intake of received documents and the source mail of a draft';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('purchasing_mail_intake');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('owner_user_id', Types::GUID);
        $table->addColumn('mail_address_id', Types::GUID);
        $table->addColumn('verdict', Types::STRING, ['length' => 16]);
        $table->addColumn('outcome', Types::STRING, ['length' => 16]);
        $table->addColumn('drafts', Types::INTEGER);
        // The indexes of the attachments already made into drafts, so a run that starts again files each once.
        $table->addColumn('filed', Types::JSON);
        $table->addColumn('skipped', Types::JSON);
        $table->addColumn('skipped_count', Types::INTEGER);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        // The recent mails of a person, newest first, on the settings page.
        $table->addIndex(['owner_user_id', 'created_at'], 'idx_purchasing_mail_intake_owner');

        $document = $schema->getTable('received_document');
        $document->addColumn('source_mail_id', Types::GUID, ['notnull' => false]);
        $document->addIndex(['source_mail_id'], 'idx_received_document_source_mail');
    }

    public function down(Schema $schema): void
    {
        $document = $schema->getTable('received_document');
        $document->dropIndex('idx_received_document_source_mail');
        $document->dropColumn('source_mail_id');
        $schema->dropTable('purchasing_mail_intake');
    }
}
