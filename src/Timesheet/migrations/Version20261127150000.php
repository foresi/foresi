<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Infrastructure\Doctrine\RunsOnlyForReal;
use Foresi\Shared\Infrastructure\Migration\ModuleRename;

/**
 * The time report is Timesheet's list now: its saved views take the key
 * timesheet.time_report instead of work.time_report, and the views it ships
 * their system keys under it. Going down names them the old way again.
 */
final class Version20261127150000 extends AbstractMigration implements RunsOnlyForReal
{
    private const array LISTS = ['work.time_report' => 'timesheet.time_report'];

    public function getDescription(): string
    {
        return 'Name the list of the time report after the module timesheet';
    }

    public function up(Schema $schema): void
    {
        // The rows change in postUp().
    }

    public function postUp(Schema $schema): void
    {
        new ModuleRename($this->connection)->renameListKeys(self::LISTS);
    }

    public function down(Schema $schema): void
    {
        // The rows change in postDown().
    }

    public function postDown(Schema $schema): void
    {
        new ModuleRename($this->connection)->renameListKeys(array_flip(self::LISTS));
    }
}
