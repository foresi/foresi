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
 * Refunds: a payment gets the way its money went. Every payment stored so far was
 * received, so the column defaults to incoming.
 */
final class Version20261105100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give a payment its direction, incoming or refund';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('payment')->addColumn('direction', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'incoming']);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('payment')->dropColumn('direction');
    }
}
