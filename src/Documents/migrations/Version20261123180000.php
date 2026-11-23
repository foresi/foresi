<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The trash of documents: a cancelled document can go there, leaving the lists
 * while it keeps its history, files and template version, and come back.
 */
final class Version20261123180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let cancelled documents go to the trash';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('documents_document');
        $table->addColumn('trashed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('trashed_by', Types::GUID, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('documents_document');
        $table->dropColumn('trashed_by');
        $table->dropColumn('trashed_at');
    }
}
