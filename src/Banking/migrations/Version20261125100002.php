<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every movement has a channel and a source now: the step before filled them in.
 */
final class Version20261125100002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make the channel and source of bank movements required';
    }

    public function up(Schema $schema): void
    {
        $movement = $schema->getTable('bank_movement');
        $movement->modifyColumn('channel', ['notnull' => true]);
        $movement->modifyColumn('source_id', ['notnull' => true]);
    }

    public function down(Schema $schema): void
    {
        $movement = $schema->getTable('bank_movement');
        $movement->modifyColumn('channel', ['notnull' => false]);
        $movement->modifyColumn('source_id', ['notnull' => false]);
    }
}
