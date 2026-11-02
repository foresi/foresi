<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A firm's own mail texts. A new table, so nothing to backfill: a firm without a
 * row sends the built-in text.
 */
final class Version20261102000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mail templates of a firm';
    }

    public function up(Schema $schema): void
    {
        $template = $schema->createTable('mail_template');
        $template->addColumn('id', Types::GUID);
        $template->addColumn('tenant_id', 'tenant_id');
        $template->addColumn('legal_entity_id', Types::GUID);
        $template->addColumn('kind', Types::STRING, ['length' => 16]);
        $template->addColumn('language', Types::STRING, ['length' => 8]);
        $template->addColumn('subject', Types::STRING, ['length' => 255]);
        $template->addColumn('body', Types::TEXT);
        $template->setPrimaryKey(['id']);
        $template->addIndex(['tenant_id'], 'idx_mail_template_tenant');
        $template->addUniqueIndex(['legal_entity_id', 'kind', 'language'], 'uniq_mail_template_firm_kind_language');
        $template->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_mail_template_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('mail_template');
    }
}
