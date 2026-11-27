<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A project's time budget: its dated changes, and which time of the project
 * uses it. Both moved to Timesheet, whose migrations create them where they are
 * missing and move the counting off the project; this stays, doing nothing, so
 * a database that ran it keeps a known version.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the time budget of a project';
    }

    public function up(Schema $schema): void
    {
        $this->write('The time budget of a project is created by the migrations of Timesheet.');
    }

    public function down(Schema $schema): void
    {
    }
}
