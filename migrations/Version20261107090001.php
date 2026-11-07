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
 * The options of an iDoklad import: the day the firm became a VAT payer, as the
 * person chose it for a run and as a check or run used it. Earlier jobs and runs
 * had none, so the columns start empty.
 */
final class Version20261107090001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the options of iDoklad import jobs and runs';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('idoklad_job')->addColumn('options', Types::JSON, ['notnull' => false]);
        $schema->getTable('idoklad_import_run')->addColumn('options', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('idoklad_import_run')->dropColumn('options');
        $schema->getTable('idoklad_job')->dropColumn('options');
    }
}
