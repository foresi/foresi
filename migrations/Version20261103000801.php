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
 * The time zone of a tenant's days. It starts empty: every tenant follows the
 * country of its first active firm until the operator sets one, see ADR 0017.
 */
final class Version20261103000801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give each tenant an optional time zone';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('tenant')->addColumn('timezone', Types::STRING, ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('tenant')->dropColumn('timezone');
    }
}
