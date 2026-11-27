<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The events of a contact are the address book's now: the stored
 * party.contact.{created,updated,archived,restored} become contacts.contact.*
 * in the event log and in the webhook deliveries, and a webhook endpoint that
 * named one is given the new name. An endpoint that took every event of Party
 * ("party.*") is given every event of the address book too ("contacts.*"), so it
 * keeps receiving the changes of contacts. Party wrote these rows, so the rename
 * is Party's and runs on every instance, with or without the address book.
 * Tables of modules that are not installed are left alone. Going down names them
 * the old way again.
 */
final class Version20261127140000 extends AbstractMigration
{
    private const array EVENTS = ['created', 'updated', 'archived', 'restored'];
    private const string PARTY_ALL = 'party.*';
    private const string CONTACTS_ALL = 'contacts.*';

    public function getDescription(): string
    {
        return 'Rename the events of a contact to contacts.contact.*';
    }

    public function up(Schema $schema): void
    {
        // The rows change in postUp(), read and written in PHP, the same on SQLite and PostgreSQL.
    }

    public function postUp(Schema $schema): void
    {
        $renames = $this->renames();
        foreach ($renames as $old => $new) {
            $this->rename($schema, $old, $new);
        }
        if ($schema->hasTable('webhook_endpoint')) {
            foreach ($this->connection->fetchAllAssociative('SELECT id, event_types FROM webhook_endpoint') as $row) {
                $types = $this->types($row['event_types']);
                if (null === $types) {
                    continue;
                }
                $renamed = array_map(static fn (string $type): string => $renames[$type] ?? $type, $types);
                if (\in_array(self::PARTY_ALL, $renamed, true) && !\in_array(self::CONTACTS_ALL, $renamed, true)) {
                    $renamed[] = self::CONTACTS_ALL;
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
        $renames = array_flip($this->renames());
        foreach ($renames as $new => $old) {
            $this->rename($schema, $new, $old);
        }
        if ($schema->hasTable('webhook_endpoint')) {
            foreach ($this->connection->fetchAllAssociative('SELECT id, event_types FROM webhook_endpoint') as $row) {
                $types = $this->types($row['event_types']);
                if (null === $types) {
                    continue;
                }
                $renamed = array_map(static fn (string $type): string => $renames[$type] ?? $type, $types);
                // "party.*" took every contact event before; "contacts.*" names none of Party's.
                $renamed = array_map(static fn (string $type): string => self::CONTACTS_ALL === $type ? self::PARTY_ALL : $type, $renamed);
                $this->store($row['id'], $types, array_values(array_unique($renamed)));
            }
        }
    }

    /**
     * @return array<string, string> the new name by the old one
     */
    private function renames(): array
    {
        $renames = [];
        foreach (self::EVENTS as $event) {
            $renames['party.contact.'.$event] = 'contacts.contact.'.$event;
        }

        return $renames;
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
