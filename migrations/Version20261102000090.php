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
 * The last test of a firm's mail server: when it ran and, if it failed, why.
 * Both start empty, as no test result was kept before.
 */
final class Version20261102000090 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the last test result of a firm mail server';
    }

    public function up(Schema $schema): void
    {
        $server = $schema->getTable('firm_mail_server');
        $server->addColumn('last_test_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $server->addColumn('last_test_error', Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $server = $schema->getTable('firm_mail_server');
        $server->dropColumn('last_test_at');
        $server->dropColumn('last_test_error');
    }
}
