<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A change of a project's time budget is Timesheet's event now: the stored
 * work.project_budget.changed becomes timesheet.project_budget.changed in the
 * event log and in the webhook deliveries, and a webhook endpoint that named
 * it is given the new name. An endpoint that took every event of Work
 * ("work.*") is given the new name too, so it keeps receiving the changes of
 * budgets. Tables of modules that are not installed are left alone. Going down
 * names it the old way again.
 */
final class Version20261127120002 extends AbstractMigration
{
    private const string OLD = 'work.project_budget.changed';
    private const string NEW = 'timesheet.project_budget.changed';
    private const string WORK_ALL = 'work.*';

    public function getDescription(): string
    {
        return 'Rename the event of a change of a project budget to timesheet.project_budget.changed';
    }

    public function up(Schema $schema): void
    {
        // The rows change in postUp(), read and written in PHP, the same on SQLite and PostgreSQL.
    }

    public function postUp(Schema $schema): void
    {
        $this->rename($schema, self::OLD, self::NEW);
        if ($schema->hasTable('webhook_endpoint')) {
            foreach ($this->connection->fetchAllAssociative('SELECT id, event_types FROM webhook_endpoint') as $row) {
                $types = $this->types($row['event_types']);
                if (null === $types) {
                    continue;
                }
                $renamed = array_map(static fn (string $type): string => self::OLD === $type ? self::NEW : $type, $types);
                if (\in_array(self::WORK_ALL, $renamed, true) && !\in_array(self::NEW, $renamed, true)) {
                    $renamed[] = self::NEW;
                }
                $this->store($row['id'], $types, array_values(array_unique($renamed)));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // The rows change in postDown().
    }

    public function postDown(Schema $schema): void
    {
        $this->rename($schema, self::NEW, self::OLD);
        if ($schema->hasTable('webhook_endpoint')) {
            foreach ($this->connection->fetchAllAssociative('SELECT id, event_types FROM webhook_endpoint') as $row) {
                $types = $this->types($row['event_types']);
                if (null === $types) {
                    continue;
                }
                $renamed = array_map(static fn (string $type): string => self::NEW === $type ? self::OLD : $type, $types);
                $this->store($row['id'], $types, array_values(array_unique($renamed)));
            }
        }
    }

    private function rename(Schema $schema, string $from, string $to): void
    {
        if ($schema->hasTable('event_log')) {
            $this->connection->executeStatement('UPDATE event_log SET name = ? WHERE name = ?', [$to, $from]);
        }
        if ($schema->hasTable('webhook_delivery')) {
            $this->connection->executeStatement('UPDATE webhook_delivery SET event_type = ? WHERE event_type = ?', [$to, $from]);
        }
    }

    /**
     * @return list<string>|null null for a value that is no list of names
     */
    private function types(mixed $json): ?array
    {
        $types = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($types) || !array_is_list($types)) {
            return null;
        }
        $names = [];
        foreach ($types as $type) {
            if (!\is_string($type)) {
                return null;
            }
            $names[] = $type;
        }

        return $names;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private function store(mixed $id, array $before, array $after): void
    {
        if ($before !== $after) {
            $this->connection->update('webhook_endpoint', ['event_types' => json_encode($after, \JSON_THROW_ON_ERROR)], ['id' => $id]);
        }
    }
}
