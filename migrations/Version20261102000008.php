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
 * Why a send or its copy failed, as a stable name and the SMTP code, so the screen
 * shows the reason in the user's language. Rows from before keep their English
 * text, which the screen still shows.
 */
final class Version20261102000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the reason of a failed send as a code';
    }

    public function up(Schema $schema): void
    {
        $send = $schema->getTable('invoice_send');
        foreach (['last_error', 'copy_error'] as $prefix) {
            $send->addColumn($prefix.'_code', Types::STRING, ['length' => 32, 'notnull' => false]);
            $send->addColumn($prefix.'_smtp', Types::SMALLINT, ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $send = $schema->getTable('invoice_send');
        foreach (['last_error_code', 'last_error_smtp', 'copy_error_code', 'copy_error_smtp'] as $column) {
            $send->dropColumn($column);
        }
    }
}
