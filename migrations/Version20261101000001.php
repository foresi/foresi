<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Domain\Id;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Moves every entity from an integer key to a UUIDv7 string, keeping all data.
 *
 * Rows get their new id in the order of the old one, so an order by id means
 * what it meant before. Each old id has one new id per entity class. A record
 * that is gone but still named by the audit log or an import snapshot gets a
 * generated id too, the same one wherever it is named, so its history stays
 * together. Classes are named as literals: this is a record of how the data
 * looked, and must not follow later renames.
 *
 * The audit log is rewritten here on purpose: its entries stay append-only for
 * the application, but an id they hold would otherwise point at nothing.
 */
final class Version20261101000001 extends AbstractMigration
{
    private const int BATCH = 5000;

    private const string USER = 'App\Identity\Domain\User';
    private const string MEMBERSHIP = 'App\Identity\Domain\Membership';
    private const string PARTY = 'App\Party\Domain\Party';
    private const string ADDRESS = 'App\Party\Domain\PartyAddress';
    private const string EMAIL = 'App\Party\Domain\PartyEmail';
    private const string ACCOUNT = 'App\Party\Domain\PartyBankAccount';
    private const string LINK = 'App\Party\Domain\ContactLink';
    private const string LEGAL_ENTITY = 'App\Party\Domain\LegalEntity';
    private const string PROFILE = 'App\Import\Domain\ImportProfile';
    private const string IMPORT = 'App\Import\Domain\Import';

    /**
     * Parents before children: a table gets its foreign keys back when it is
     * rebuilt, and on SQLite rebuilding a parent would cascade into its children.
     *
     * @var array<string, array{class-string|string|null, array<string, string>}> table => the class its ids are mapped under (null: nothing refers to them), and its references, column => class
     */
    private const array TABLES = [
        'app_user' => [self::USER, []],
        'party' => [self::PARTY, []],
        'legal_entity' => [self::LEGAL_ENTITY, []],
        'import_profile' => [self::PROFILE, []],
        'membership' => [self::MEMBERSHIP, ['user_id' => self::USER, 'party_id' => self::PARTY]],
        'party_address' => [self::ADDRESS, ['party_id' => self::PARTY]],
        'party_email' => [self::EMAIL, ['party_id' => self::PARTY]],
        'party_bank_account' => [self::ACCOUNT, ['party_id' => self::PARTY]],
        'contact_link' => [self::LINK, ['company_id' => self::PARTY, 'person_id' => self::PARTY]],
        'import' => [self::IMPORT, ['profile_id' => self::PROFILE, 'actor_user_id' => self::USER]],
        'import_row' => [null, ['import_id' => self::IMPORT, 'record_id' => self::PARTY]],
        'import_missing_record' => [null, ['import_id' => self::IMPORT, 'record_id' => self::PARTY]],
        'import_ambiguous_match' => [null, ['import_id' => self::IMPORT, 'record_id' => self::PARTY]],
        'audit_entry' => [null, ['user_id' => self::USER]],
    ];

    /** The associations audited entities log, by class and field; every other audited value is left alone. */
    private const array AUDITED_ASSOCIATIONS = [
        self::MEMBERSHIP => ['user' => self::USER, 'party' => self::PARTY],
        self::ADDRESS => ['party' => self::PARTY],
        self::EMAIL => ['party' => self::PARTY],
        self::ACCOUNT => ['party' => self::PARTY],
        self::LINK => ['company' => self::PARTY, 'person' => self::PARTY],
    ];

    /** The children an import snapshot names, by key. */
    private const array SNAPSHOT_CHILDREN = [
        'emails' => self::EMAIL,
        'addresses' => self::ADDRESS,
        'bankAccounts' => self::ACCOUNT,
        'contactLinks' => self::LINK,
        'persons' => self::PARTY,
    ];

    /** @var array<string, array<int, string>> class => old id => new id */
    private array $ids = [];

    public function getDescription(): string
    {
        return 'Use UUIDv7 identifiers for all entities';
    }

    public function up(Schema $schema): void
    {
        $this->addColumns();
        foreach (self::TABLES as $table => [$class]) {
            if (null !== $class) {
                $this->mapIds($table, $class);
            }
        }
        foreach (self::TABLES as $table => [$class, $references]) {
            $this->fill($table, $class, $references);
        }
        $this->rewriteAuditLog();
        $this->rewriteSnapshots('import_row');
        $this->rewriteSnapshots('import_missing_record');
        $this->replaceColumns();
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Integer ids cannot be restored: the mapping is not kept.');
    }

    private function addColumns(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $guid = $platform->getGuidTypeDeclarationSQL([]);
        foreach (self::TABLES as $table => [, $references]) {
            $name = $this->connection->quoteSingleIdentifier($table);
            foreach (['id', ...array_keys($references)] as $column) {
                $this->connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN new_%s %s', $name, $column, $guid));
            }
        }
    }

    /**
     * Generates the ids of a table that others refer to, in the order of the old ones.
     */
    private function mapIds(string $table, string $class): void
    {
        $last = 0;
        do {
            $ids = $this->connection->fetchFirstColumn(
                \sprintf('SELECT id FROM %s WHERE id > ? ORDER BY id LIMIT %d', $this->connection->quoteSingleIdentifier($table), self::BATCH),
                [$last],
                [ParameterType::INTEGER],
            );
            foreach ($ids as $id) {
                $last = $this->whole($id);
                $this->ids[$class][$last] = Id::generate();
            }
        } while (self::BATCH === \count($ids));
    }

    /**
     * @param array<string, string> $references column => class
     */
    private function fill(string $table, ?string $class, array $references): void
    {
        $quoted = $this->connection->quoteSingleIdentifier($table);
        $columns = array_keys($references);
        $select = implode(', ', ['id', ...$columns]);
        $set = implode(', ', array_map(static fn (string $column): string => \sprintf('new_%1$s = ?', $column), ['id', ...$columns]));
        $last = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT %s FROM %s WHERE id > ? ORDER BY id LIMIT %d', $select, $quoted, self::BATCH),
                [$last],
                [ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $last = $this->whole($row['id']);
                $params = [null === $class ? Id::generate() : $this->ids[$class][$last]];
                foreach ($references as $column => $target) {
                    $params[] = null === $row[$column] ? null : $this->id($target, $this->whole($row[$column]));
                }
                $this->connection->executeStatement(\sprintf('UPDATE %s SET %s WHERE id = ?', $quoted, $set), [...$params, $last]);
            }
        } while (self::BATCH === \count($rows));
    }

    /**
     * The entity id of an entry follows the map of its class, and the ids its
     * changes hold follow the class of the association. The rest of the
     * changes is what users typed: a number there is a value, not an id.
     */
    private function rewriteAuditLog(): void
    {
        $last = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id, entity_class, entity_id, changes FROM audit_entry WHERE id > ? ORDER BY id LIMIT %d', self::BATCH),
                [$last],
                [ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $last = $this->whole($row['id']);
                $class = $row['entity_class'];
                $entityId = $row['entity_id'];
                \assert(\is_string($class) && \is_string($entityId) && \is_string($row['changes']));
                $changes = $this->decode($row['changes']);
                foreach (self::AUDITED_ASSOCIATIONS[$class] ?? [] as $field => $target) {
                    if (\is_array($changes[$field] ?? null)) {
                        $changes[$field] = array_map(fn (mixed $value): mixed => $this->replaced($target, $value), $changes[$field]);
                    }
                }
                $this->connection->executeStatement(
                    'UPDATE audit_entry SET entity_id = ?, changes = ? WHERE id = ?',
                    [ctype_digit($entityId) ? $this->id($class, (int) $entityId) : $entityId, json_encode((object) $changes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION), $last],
                );
            }
        } while (self::BATCH === \count($rows));
    }

    /**
     * What an import noted about a record: the ids of the children it added and
     * of those the record had, which the undo looks up by id, in the map of their
     * class. A child removed since keeps the id its audit entries got.
     */
    private function rewriteSnapshots(string $table): void
    {
        $before = $this->connection->quoteSingleIdentifier('before');
        $last = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id, %s AS snapshot FROM %s WHERE id > ? AND %1$s IS NOT NULL ORDER BY id LIMIT %d', $before, $table, self::BATCH),
                [$last],
                [ParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $last = $this->whole($row['id']);
                \assert(\is_string($row['snapshot']));
                $snapshot = $this->decode($row['snapshot']);
                foreach (['added', 'children'] as $key) {
                    if (\is_array($snapshot[$key] ?? null)) {
                        $snapshot[$key] = $this->replacedChildren($snapshot[$key]);
                    }
                }
                if (\is_array($snapshot['generalEmail'] ?? null) && isset($snapshot['generalEmail']['id'])) {
                    $snapshot['generalEmail']['id'] = $this->replaced(self::EMAIL, $snapshot['generalEmail']['id']);
                }
                $this->connection->executeStatement(
                    \sprintf('UPDATE %s SET %s = ? WHERE id = ?', $table, $before),
                    [json_encode((object) $snapshot, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION), $last],
                );
            }
        } while (self::BATCH === \count($rows));
    }

    /**
     * @param array<array-key, mixed> $children
     *
     * @return array<array-key, mixed>
     */
    private function replacedChildren(array $children): array
    {
        foreach (self::SNAPSHOT_CHILDREN as $key => $class) {
            if (\is_array($children[$key] ?? null)) {
                $children[$key] = array_map(fn (mixed $value): mixed => $this->replaced($class, $value), $children[$key]);
            }
        }

        return $children;
    }

    /**
     * Foreign keys go first, on their own: with them gone, rebuilding a table on
     * SQLite cannot cascade. Then each table swaps its columns and gets its keys
     * and indexes back as they were.
     */
    private function replaceColumns(): void
    {
        $manager = $this->connection->createSchemaManager();
        $comparator = $manager->createComparator();
        $foreignKeys = [];
        foreach (self::TABLES as $table => $config) {
            $foreignKeys[$table] = $manager->introspectTable($table)->getForeignKeys();
            $this->alter($comparator, $manager, $table, static function (Table $definition): void {
                foreach ($definition->getForeignKeys() as $name => $foreignKey) {
                    $definition->removeForeignKey($name);
                }
            });
        }
        foreach (self::TABLES as $table => [, $references]) {
            $columns = ['id', ...array_keys($references)];
            $indexes = array_filter($manager->introspectTable($table)->getIndexes(), static fn (Index $index): bool => !$index->isPrimary() && [] !== array_intersect($index->getColumns(), $columns));
            $required = [];
            foreach ($columns as $column) {
                $required[$column] = $manager->introspectTable($table)->getColumn($column)->getNotnull();
            }
            // Two steps: the comparator would take a new column renamed over a dropped one for that same column.
            $this->alter($comparator, $manager, $table, static function (Table $definition) use ($columns, $indexes): void {
                foreach ($indexes as $name => $index) {
                    $definition->dropIndex($name);
                }
                $definition->dropPrimaryKey();
                foreach ($columns as $column) {
                    $definition->dropColumn($column);
                }
            });
            $this->alter($comparator, $manager, $table, static function (Table $definition) use ($columns, $indexes, $required, $foreignKeys, $table): void {
                foreach ($columns as $column) {
                    $definition->renameColumn('new_'.$column, $column);
                    $definition->modifyColumn($column, ['type' => Type::getType(Types::GUID), 'notnull' => $required[$column]]);
                }
                $definition->setPrimaryKey(['id']);
                foreach ($indexes as $index) {
                    if ($index->isUnique()) {
                        $definition->addUniqueIndex($index->getColumns(), $index->getName());
                    } else {
                        $definition->addIndex($index->getColumns(), $index->getName());
                    }
                }
                foreach ($foreignKeys[$table] as $foreignKey) {
                    $definition->addForeignKeyConstraint(
                        $foreignKey->getForeignTableName(),
                        $foreignKey->getLocalColumns(),
                        $foreignKey->getForeignColumns(),
                        $foreignKey->getOptions(),
                        $foreignKey->getName(),
                    );
                }
            });
        }
    }

    /**
     * @param AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> $manager
     * @param callable(Table): void                                            $change
     */
    private function alter(Comparator $comparator, AbstractSchemaManager $manager, string $table, callable $change): void
    {
        $from = $manager->introspectTable($table);
        $to = clone $from;
        $change($to);
        foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($comparator->compareTables($from, $to)) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function replaced(string $class, mixed $value): mixed
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? $this->id($class, (int) $value) : $value;
    }

    /**
     * The new id of an old one; a record that is gone gets one on first sight.
     */
    private function id(string $class, int $old): string
    {
        return $this->ids[$class][$old] ??= Id::generate();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    private function whole(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : throw new \UnexpectedValueException('An id column holds a whole number.');
    }
}
