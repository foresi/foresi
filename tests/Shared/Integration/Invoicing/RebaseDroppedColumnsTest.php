<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Integration\Invoicing;

use Foresi\Tests\Shared\Support\RebaseTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The invoicing defaults of a party left its table for Invoicing: a database on the global
 * history still has the columns, and Invoicing's migration drops them after the rebase.
 */
final class RebaseDroppedColumnsTest extends RebaseTestCase
{
    public function testColumnsTheBaselinesNoLongerMakeAndALaterMigrationDropsDoNotStopTheRebase(): void
    {
        $tables = $this->tables();
        $this->onGlobalHistory();
        // The global history gave the party its invoicing defaults; Party's baseline no longer makes them.
        foreach (['invoicing_due_days INT', 'invoicing_currency CHAR(3)', 'invoicing_language CHAR(2)', 'invoicing_payment_method VARCHAR(32)', 'invoicing_reverse_charge BOOLEAN', 'invoicing_send_reminders BOOLEAN', 'invoicing_hourly_rate_minor BIGINT', 'invoicing_hourly_rate_currency CHAR(3)'] as $column) {
            $this->db->executeStatement('ALTER TABLE party ADD COLUMN '.$column);
        }

        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::SUCCESS, $rebase->getStatusCode(), $rebase->getDisplay());
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:migrate')->getStatusCode());
        self::assertSame($tables, $this->tables());
        $columns = array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getName(), $this->db->createSchemaManager()->introspectTableColumnsByUnquotedName('party'));
        self::assertSame([], array_values(array_filter($columns, static fn (string $name): bool => str_starts_with($name, 'invoicing_'))));
    }
}
