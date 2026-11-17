<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance serves one customer (ADR 0035): the migrations that follow
 * remove the tenant columns and would merge the data of several tenants. This
 * one stops before them when the database holds more than one tenant. A fresh
 * database has none yet and passes. It changes nothing, so going down is free.
 */
final class Version20261117210001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stop unless the database holds at most one tenant';
    }

    public function up(Schema $schema): void
    {
        $tenants = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tenant');
        $this->abortIf(
            $tenants > 1,
            \sprintf('This database holds %d tenants. Foresi now runs one instance per customer (ADR 0035): move each tenant but one into an instance of its own before upgrading.', $tenants),
        );
        $this->write(\sprintf('Tenants found: %d.', $tenants));
    }

    public function down(Schema $schema): void
    {
    }
}
