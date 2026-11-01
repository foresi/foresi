<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Identity\Domain\Membership;
use App\Party\Domain\ContactLink;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Infrastructure\Doctrine\VatIdType;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Doctrine\RefuseMigrationRehearsal;
use App\Tenancy\Infrastructure\Doctrine\TenantIdType;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * Runs the move to UUIDv7 on a scratch database holding one of everything in
 * the integer shape. On SQLite that is an in-memory database; when the suite
 * runs on PostgreSQL it is a schema of its own in the test database, so the
 * migration is proven on both platforms.
 */
final class UuidMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261031000004';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'uuid_migration_test';
    private const string AT = '2026-10-31 12:00:00';

    private const int USER = 4;
    private const int DELETED_USER = 40;
    private const int COMPANY = 3;
    private const int PERSON = 10;
    private const int EARLIER_PARTY = 2;
    private const int DELETED_PARTY = 99;
    private const int ADDRESS = 5;
    private const int EMAIL = 6;
    private const int DELETED_EMAIL = 66;
    private const int ACCOUNT = 7;
    private const int LINK = 8;
    private const int MEMBERSHIP = 9;
    private const int LEGAL_ENTITY = 11;
    private const int PROFILE = 12;
    private const int IMPORT = 13;

    private Connection $db;

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->onPostgres()) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testEveryReferenceFollowsItsRow(): void
    {
        $this->migrateTo(null);

        $partyId = $this->text("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'");
        $personId = $this->text("SELECT id FROM party WHERE display_name = 'Example Person'");
        $userId = $this->text('SELECT id FROM app_user');

        self::assertTrue(Id::isValid($partyId));
        self::assertTrue(Id::isValid($personId));
        self::assertTrue(Id::isValid($userId));
        self::assertSame($partyId, $this->text('SELECT party_id FROM party_address'));
        self::assertSame($partyId, $this->text('SELECT party_id FROM party_email'));
        self::assertSame($partyId, $this->text('SELECT party_id FROM party_bank_account'));
        self::assertSame($partyId, $this->text('SELECT company_id FROM contact_link'));
        self::assertSame($personId, $this->text('SELECT person_id FROM contact_link'));
        self::assertSame($userId, $this->text('SELECT user_id FROM membership'));
        self::assertSame($personId, $this->text('SELECT party_id FROM membership'));
        self::assertSame($userId, $this->text('SELECT actor_user_id FROM import'));
        self::assertSame($this->text('SELECT id FROM import_profile'), $this->text('SELECT profile_id FROM import'));
        self::assertSame($this->text('SELECT id FROM import'), $this->text('SELECT import_id FROM import_row'));
        self::assertSame($this->text('SELECT id FROM import'), $this->text('SELECT import_id FROM import_missing_record'));
        self::assertSame($this->text('SELECT id FROM import'), $this->text('SELECT import_id FROM import_ambiguous_match'));
        self::assertSame($partyId, $this->text('SELECT record_id FROM import_row'));
        self::assertSame($personId, $this->text('SELECT record_id FROM import_missing_record'));
        self::assertSame($personId, $this->text('SELECT record_id FROM import_ambiguous_match'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM party WHERE id = ?', [$partyId]));
        self::assertSame(self::TENANT, $this->text('SELECT tenant_id FROM party_address'));
    }

    public function testBatchesLoseNothingAndKeepTheOrder(): void
    {
        $migration = $this->migrations()->getMigrationRepository()->getMigration(new Version('DoctrineMigrations\Version20261101000001'))->getMigration();
        $batch = new \ReflectionClassConstant($migration, 'BATCH')->getValue();
        self::assertIsInt($batch);
        $rows = $batch + 1;
        $seededAudit = $this->rows('SELECT COUNT(*) FROM audit_entry');
        $snapshot = json_encode(['added' => ['emails' => [self::EMAIL]], 'children' => ['emails' => [self::EMAIL, self::DELETED_EMAIL]]], \JSON_THROW_ON_ERROR);
        $changes = ['party' => [null, self::COMPANY]];

        $this->db->beginTransaction();
        // The row without a snapshot sits before the others: the rewrite must step over it.
        $this->bulkImportRow(2, null);
        for ($i = 0; $i < $rows; ++$i) {
            $this->audit(\sprintf('Bulk %05d', $i), PartyEmail::class, self::EMAIL, 'updated', $changes, self::USER);
            $this->bulkImportRow($i + 3, $snapshot);
        }
        $this->db->commit();

        $this->migrateTo(null);

        self::assertSame($seededAudit + $rows, $this->rows('SELECT COUNT(*) FROM audit_entry'));
        self::assertSame($rows + 2, $this->rows('SELECT COUNT(*) FROM import_row'));

        self::assertSame(
            array_map(static fn (int $i): string => \sprintf('Bulk %05d', $i), range(0, $rows - 1)),
            $this->db->fetchFirstColumn("SELECT correlation_id FROM audit_entry WHERE correlation_id LIKE 'Bulk %' ORDER BY id"),
        );
        self::assertSame(range(1, $rows + 2), $this->db->fetchFirstColumn('SELECT row_number FROM import_row ORDER BY id'));

        $emailId = $this->text('SELECT id FROM party_email');
        $partyId = $this->text("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'");
        $deletedEmailId = $this->text('SELECT entity_id FROM audit_entry WHERE correlation_id = ?', ['Deleted e-mail']);
        self::assertSame([$emailId], $this->distinct("SELECT entity_id FROM audit_entry WHERE correlation_id LIKE 'Bulk %'"));
        self::assertSame([$this->text('SELECT id FROM app_user')], $this->distinct("SELECT user_id FROM audit_entry WHERE correlation_id LIKE 'Bulk %'"));
        $bulkChanges = $this->distinct("SELECT changes FROM audit_entry WHERE correlation_id LIKE 'Bulk %'");
        self::assertCount(1, $bulkChanges);
        self::assertSame(['party' => [null, $partyId]], json_decode($bulkChanges[0], true));

        $before = $this->db->quoteSingleIdentifier('before');
        self::assertSame($rows, $this->rows(\sprintf('SELECT COUNT(*) FROM import_row WHERE %s IS NOT NULL AND row_number > 2', $before)));
        $snapshots = $this->distinct(\sprintf('SELECT %s FROM import_row WHERE %1$s IS NOT NULL AND row_number > 2', $before));
        self::assertCount(1, $snapshots);
        self::assertSame(['added' => ['emails' => [$emailId]], 'children' => ['emails' => [$emailId, $deletedEmailId]]], json_decode($snapshots[0], true));
        self::assertNull($this->db->fetchOne(\sprintf('SELECT %s FROM import_row WHERE row_number = 2', $before)));
    }

    public function testEveryIdIsAUuidV7(): void
    {
        $this->migrateTo(null);

        foreach (['app_user', 'membership', 'party', 'party_address', 'party_email', 'party_bank_account', 'contact_link', 'legal_entity', 'import_profile', 'import', 'import_row', 'import_missing_record', 'import_ambiguous_match', 'audit_entry'] as $table) {
            $ids = $this->db->fetchFirstColumn('SELECT id FROM '.$table);
            self::assertNotEmpty($ids, $table);
            foreach ($ids as $id) {
                self::assertIsString($id);
                self::assertTrue(Id::isValid($id), $table);
            }
        }
    }

    public function testTheOldOrderOfRowsSurvives(): void
    {
        $this->migrateTo(null);

        // 2, 3, 10: as text the old ids would sort 10, 2, 3.
        self::assertSame(
            ['Earlier Party', 'Example Company s.r.o.', 'Example Person'],
            $this->db->fetchFirstColumn('SELECT display_name FROM party ORDER BY id'),
        );
        self::assertSame(
            ['Created company', 'Created link', 'Created address', 'Created e-mail', 'Created account', 'Created membership', 'Created entity', 'Updated party', 'Created deleted', 'Deleted deleted', 'Deleted e-mail', 'Unknown class'],
            $this->db->fetchFirstColumn('SELECT correlation_id FROM audit_entry ORDER BY id'),
        );
    }

    public function testTheAuditLogStaysLinked(): void
    {
        $this->migrateTo(null);
        $db = $this->db;
        $partyId = $this->text("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'");
        $personId = $this->text("SELECT id FROM party WHERE display_name = 'Example Person'");
        $userId = $this->text('SELECT id FROM app_user');

        self::assertSame($partyId, $db->fetchOne('SELECT entity_id FROM audit_entry WHERE entity_class = ? AND correlation_id = ?', [Party::class, 'Created company']));
        self::assertSame($this->text('SELECT id FROM party_address'), $db->fetchOne('SELECT entity_id FROM audit_entry WHERE entity_class = ?', [PartyAddress::class]));
        self::assertSame($this->text('SELECT id FROM contact_link'), $db->fetchOne('SELECT entity_id FROM audit_entry WHERE entity_class = ?', [ContactLink::class]));
        self::assertSame($this->text('SELECT id FROM membership'), $db->fetchOne('SELECT entity_id FROM audit_entry WHERE entity_class = ?', [Membership::class]));
        self::assertSame($this->text('SELECT id FROM legal_entity'), $db->fetchOne('SELECT entity_id FROM audit_entry WHERE entity_class = ?', [LegalEntity::class]));
        self::assertSame($userId, $db->fetchOne('SELECT user_id FROM audit_entry WHERE correlation_id = ?', ['Created company']));
        self::assertNull($db->fetchOne('SELECT user_id FROM audit_entry WHERE correlation_id = ?', ['Unknown class']));

        self::assertSame(['company' => [null, $partyId], 'person' => [null, $personId], 'position' => [null, '42'], 'isPrimary' => [null, true]], $this->changes('Created link'));
        self::assertSame(['party' => [null, $partyId], 'label' => [null, '7'], 'type' => [null, 'billing']], $this->changes('Created address'));
        self::assertSame(['user' => [null, $userId], 'party' => [$personId, null], 'role' => [null, 'owner']], $this->changes('Created membership'));
        self::assertSame(['displayName' => ['12', '13']], $this->changes('Updated party'));
    }

    public function testEntriesOfADeletedRecordStayTogether(): void
    {
        $this->migrateTo(null);
        $db = $this->db;

        $created = $db->fetchOne('SELECT entity_id FROM audit_entry WHERE correlation_id = ?', ['Created deleted']);
        $deleted = $db->fetchOne('SELECT entity_id FROM audit_entry WHERE correlation_id = ?', ['Deleted deleted']);

        self::assertIsString($created);
        self::assertTrue(Id::isValid($created));
        self::assertSame($created, $deleted);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM party WHERE id = ?', [$created]));

        $byUser = $db->fetchOne('SELECT user_id FROM audit_entry WHERE correlation_id = ?', ['Deleted deleted']);
        self::assertIsString($byUser);
        self::assertTrue(Id::isValid($byUser));
        self::assertSame($byUser, $db->fetchOne('SELECT user_id FROM audit_entry WHERE correlation_id = ?', ['Created deleted']));
        self::assertNotSame($this->text('SELECT id FROM app_user'), $byUser);

        // The same old number in another class is another record.
        $sameNumber = $db->fetchOne('SELECT entity_id FROM audit_entry WHERE correlation_id = ?', ['Deleted e-mail']);
        self::assertNotSame($created, $sameNumber);
    }

    public function testAnEntryOfAClassThatIsGoneKeepsAnIdOfItsOwn(): void
    {
        $this->migrateTo(null);

        $id = $this->db->fetchOne('SELECT entity_id FROM audit_entry WHERE correlation_id = ?', ['Unknown class']);
        self::assertIsString($id);
        self::assertTrue(Id::isValid($id));
    }

    public function testImportSnapshotsNameTheNewIds(): void
    {
        $this->migrateTo(null);
        $partyId = $this->text("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'");
        $personId = $this->text("SELECT id FROM party WHERE display_name = 'Example Person'");
        $emailId = $this->text('SELECT id FROM party_email');
        $deletedEmailId = $this->db->fetchOne('SELECT entity_id FROM audit_entry WHERE correlation_id = ?', ['Deleted e-mail']);

        $before = json_decode($this->text('SELECT '.$this->db->quoteSingleIdentifier('before').' FROM import_row'), true);

        self::assertSame([
            'party' => ['displayName' => '123', 'dueDays' => 14],
            'generalEmail' => ['id' => $emailId, 'address' => 'general@example.com'],
            'added' => [
                'emails' => [$emailId],
                'addresses' => [$this->text('SELECT id FROM party_address')],
                'bankAccounts' => [$this->text('SELECT id FROM party_bank_account')],
                'contactLinks' => [$this->text('SELECT id FROM contact_link')],
                'persons' => [$personId],
            ],
            'children' => ['emails' => [$emailId, $deletedEmailId]],
        ], $before);
        self::assertNotSame($partyId, $emailId);
        self::assertSame(['archived' => false], json_decode($this->text('SELECT '.$this->db->quoteSingleIdentifier('before').' FROM import_missing_record'), true));
    }

    public function testTheKeysAreRestored(): void
    {
        $this->migrateTo(null);
        $manager = $this->db->createSchemaManager();

        foreach (['party_address' => 'party', 'party_email' => 'party', 'party_bank_account' => 'party', 'membership' => null, 'contact_link' => null, 'import' => 'import_profile', 'import_row' => 'import', 'import_missing_record' => 'import', 'import_ambiguous_match' => 'import'] as $table => $parent) {
            $keys = array_values($manager->introspectTable($table)->getForeignKeys());
            self::assertNotEmpty($keys, $table);
            if (null !== $parent) {
                self::assertSame($parent, $keys[0]->getForeignTableName());
            }
        }
        self::assertCount(2, $manager->introspectTable('contact_link')->getForeignKeys());
        self::assertCount(2, $manager->introspectTable('membership')->getForeignKeys());

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->db->insert('contact_link', [
            'id' => Id::generate(), 'tenant_id' => self::TENANT, 'position' => '', 'is_primary' => 0,
            'company_id' => $this->db->fetchOne('SELECT company_id FROM contact_link'),
            'person_id' => $this->db->fetchOne('SELECT person_id FROM contact_link'),
        ], ['is_primary' => ParameterType::BOOLEAN]);
    }

    public function testRowDeletionStillCascadesFromTheImport(): void
    {
        $this->migrateTo(null);
        $this->enableForeignKeys();

        $this->db->executeStatement('DELETE FROM import');

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM import_row'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM import_missing_record'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM import_ambiguous_match'));
    }

    public function testAFailureLeavesEverythingAsItWas(): void
    {
        if ($this->onPostgres()) {
            self::markTestSkipped('PostgreSQL refuses this broken value when it is stored.');
        }
        $this->db->executeStatement("UPDATE audit_entry SET changes = 'not json' WHERE correlation_id = 'Unknown class'");

        try {
            $this->migrateTo(null);
            self::fail('The migration must fail on this value.');
        } catch (\JsonException) {
            self::assertFalse($this->db->isTransactionActive());
        }

        self::assertSame(self::COMPANY, $this->db->fetchOne("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'"));
        self::assertSame(self::COMPANY, $this->db->fetchOne('SELECT company_id FROM contact_link'));
    }

    public function testEnforcedForeignKeysLoseNoRows(): void
    {
        $this->enableForeignKeys();

        $this->migrateTo(null);

        $importId = $this->text('SELECT id FROM import');
        foreach (['import_row', 'import_missing_record', 'import_ambiguous_match'] as $table) {
            self::assertSame($importId, $this->text('SELECT import_id FROM '.$table));
        }
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM party_address'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM contact_link'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM membership'));
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM party'));
    }

    public function testADryRunIsRefusedBeforeAnyChange(): void
    {
        $listeners = new EventManager();
        $listeners->addEventListener(Events::onMigrationsMigrating, new RefuseMigrationRehearsal());
        $migrations = $this->migrations();
        $migrations->setService(EventDispatcher::class, new EventDispatcher($this->db, $listeners));

        try {
            $this->plan($migrations, new MigratorConfiguration()->setDryRun(true));
            self::fail('A dry run must be refused.');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('Version20261101000001', $refused->getMessage());
        }

        self::assertSame(self::COMPANY, $this->db->fetchOne("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'"));
        self::assertFalse($this->db->isTransactionActive());

        $this->migrateTo(null);

        self::assertTrue(Id::isValid($this->text("SELECT id FROM party WHERE display_name = 'Example Company s.r.o.'")));
    }

    public function testIdsThatAreUuidsAlreadyStopTheMigrationWithTheWayOut(): void
    {
        // Without the listener a dry run converts everything and records nothing.
        $this->plan($this->migrations(), new MigratorConfiguration()->setDryRun(true));

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessageIsOrContains('doctrine:migrations:version "DoctrineMigrations\Version20261101000001" --add');
        $this->migrateTo(null);
    }

    public function testItCannotBeReversed(): void
    {
        $this->migrateTo(null);

        $this->expectException(IrreversibleMigration::class);
        $this->migrateTo(self::BEFORE);
    }

    public function testAnEmptyDatabaseMigrates(): void
    {
        foreach (['audit_entry', 'import_ambiguous_match', 'import_missing_record', 'import_row', 'import', 'import_profile', 'contact_link', 'party_bank_account', 'party_email', 'party_address', 'membership', 'legal_entity', 'party', 'app_user'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table);
        }

        $this->migrateTo(null);

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM party'));
    }

    private function seed(): void
    {
        $this->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => self::AT]);
        $this->insert('app_user', ['id' => self::USER, 'email' => 'user@example.com', 'display_name' => 'User', 'locale' => 'en', 'password_hash' => 'x']);
        // Not in id order, as a restored dump need not be.
        $this->party(self::PERSON, 'Example Person', 'person');
        $this->party(self::COMPANY, 'Example Company s.r.o.', 'company');
        $this->party(self::EARLIER_PARTY, 'Earlier Party', 'company');
        $this->tenantRow('membership', ['id' => self::MEMBERSHIP, 'user_id' => self::USER, 'party_id' => self::PERSON, 'role' => 'owner']);
        $this->tenantRow('party_address', ['id' => self::ADDRESS, 'party_id' => self::COMPANY, 'type' => 'billing', 'label' => '', 'address_street' => 'Example Street 1', 'address_city' => 'Example City', 'address_postal_code' => '100 00', 'address_country_code' => 'CZ', 'billing_flag' => true]);
        $this->tenantRow('party_email', ['id' => self::EMAIL, 'party_id' => self::COMPANY, 'address' => 'general@example.com', 'purpose' => 'general']);
        $this->tenantRow('party_bank_account', ['id' => self::ACCOUNT, 'party_id' => self::COMPANY, 'iban' => 'DE89370400440532013000', 'label' => '', 'primary_flag' => true]);
        $this->tenantRow('contact_link', ['id' => self::LINK, 'company_id' => self::COMPANY, 'person_id' => self::PERSON, 'position' => 'Director', 'is_primary' => true]);
        $this->tenantRow('legal_entity', ['id' => self::LEGAL_ENTITY, 'name' => 'Example Firm', 'vat_status' => 'payer', 'register_entry' => '', 'bank_accounts' => '[]', 'registration_country' => 'CZ', 'registration_number' => '12345678', 'address_street' => 'Example Street 1', 'address_city' => 'Example City', 'address_postal_code' => '100 00', 'address_country_code' => 'CZ']);

        $options = ['option_mode' => 'update_existing', 'option_blank_cells' => 'keep', 'option_date_order' => 'dmy', 'option_decimal_separator' => 'comma'];
        $this->tenantRow('import_profile', $options + ['id' => self::PROFILE, 'mapping' => '{"columns":{"0":"id"}}', 'updated_at' => self::AT, 'name' => 'Profile', 'target_key' => 'contacts', 'header_signature' => str_repeat('a', 40), 'created_at' => self::AT]);
        $this->tenantRow('import', $options + [
            'id' => self::IMPORT, 'profile_id' => self::PROFILE, 'headers' => '["id"]', 'mapping' => '{"columns":{"0":"id"}}', 'state' => 'finished',
            'total' => 1, 'processed' => 1, 'created' => 0, 'updated' => 1, 'restored' => 0, 'skipped' => 0, 'archived' => 0, 'failed' => 0, 'chunks' => 1, 'chunks_done' => 1, 'archive_candidates' => 0,
            'target_key' => 'contacts', 'original_name' => 'contacts.csv', 'source_settings' => '{"headerRow":1}', 'actor_user_id' => self::USER, 'created_at' => self::AT,
        ]);
        $this->tenantRow('import_row', [
            'id' => 1, 'import_id' => self::IMPORT, 'outcome' => 'update', 'errors' => '[]', 'record_id' => self::COMPANY, 'row_number' => 1, 'chunk' => 0, 'raw' => '["3"]', 'processed_in_run' => true, 'processed_in_undo' => false,
            'before' => json_encode([
                'party' => ['displayName' => '123', 'dueDays' => 14],
                'generalEmail' => ['id' => self::EMAIL, 'address' => 'general@example.com'],
                'added' => ['emails' => [self::EMAIL], 'addresses' => [self::ADDRESS], 'bankAccounts' => [self::ACCOUNT], 'contactLinks' => [self::LINK], 'persons' => [self::PERSON]],
                'children' => ['emails' => [self::EMAIL, self::DELETED_EMAIL]],
            ], \JSON_THROW_ON_ERROR),
        ]);
        $this->tenantRow('import_missing_record', ['id' => 1, 'import_id' => self::IMPORT, 'chunk' => 1, 'record_id' => self::PERSON, 'outcome' => 'archive', 'before' => '{"archived":false}', 'processed_in_run' => true, 'processed_in_undo' => false]);
        $this->tenantRow('import_ambiguous_match', ['id' => 1, 'import_id' => self::IMPORT, 'record_id' => self::PERSON]);

        $this->audit('Created company', Party::class, self::COMPANY, 'created', ['displayName' => [null, 'Example Company s.r.o.']], self::USER);
        $this->audit('Created link', ContactLink::class, self::LINK, 'created', ['company' => [null, self::COMPANY], 'person' => [null, self::PERSON], 'position' => [null, '42'], 'isPrimary' => [null, true]], self::USER);
        $this->audit('Created address', PartyAddress::class, self::ADDRESS, 'created', ['party' => [null, self::COMPANY], 'label' => [null, '7'], 'type' => [null, 'billing']], self::USER);
        $this->audit('Created e-mail', PartyEmail::class, self::EMAIL, 'created', ['party' => [null, self::COMPANY]], self::USER);
        $this->audit('Created account', PartyBankAccount::class, self::ACCOUNT, 'created', ['party' => [null, self::COMPANY]], self::USER);
        $this->audit('Created membership', Membership::class, self::MEMBERSHIP, 'created', ['user' => [null, self::USER], 'party' => [self::PERSON, null], 'role' => [null, 'owner']], self::USER);
        $this->audit('Created entity', LegalEntity::class, self::LEGAL_ENTITY, 'created', ['name' => [null, 'Example Firm']], self::USER);
        $this->audit('Updated party', Party::class, self::COMPANY, 'updated', ['displayName' => ['12', '13']], self::USER);
        $this->audit('Created deleted', Party::class, self::DELETED_PARTY, 'created', ['displayName' => [null, 'Gone']], self::DELETED_USER);
        $this->audit('Deleted deleted', Party::class, self::DELETED_PARTY, 'deleted', ['displayName' => ['Gone', null]], self::DELETED_USER);
        $this->audit('Deleted e-mail', PartyEmail::class, self::DELETED_EMAIL, 'deleted', ['party' => [self::DELETED_PARTY, null]], null);
        $this->audit('Unknown class', 'App\Old\Thing', 1, 'created', ['value' => [null, 1]], null);
    }

    private function bulkImportRow(int $id, ?string $snapshot): void
    {
        $this->tenantRow('import_row', [
            'id' => $id, 'import_id' => self::IMPORT, 'outcome' => 'update', 'errors' => '[]', 'record_id' => self::COMPANY, 'row_number' => $id, 'chunk' => 0, 'raw' => '[]', 'processed_in_run' => true, 'processed_in_undo' => false, 'before' => $snapshot,
        ]);
    }

    private function party(int $id, string $name, string $kind): void
    {
        $this->tenantRow('party', [
            'id' => $id, 'kind' => $kind, 'display_name' => $name, 'phone' => '', 'roles' => '[]', 'private_note' => '',
            'invoicing_due_days' => 14, 'invoicing_currency' => 'CZK', 'invoicing_language' => 'cs', 'invoicing_payment_method' => 'bank_transfer', 'invoicing_reverse_charge' => false,
        ]);
    }

    /**
     * @param array<string, array{mixed, mixed}> $changes
     */
    private function audit(string $label, string $class, int $id, string $action, array $changes, ?int $user): void
    {
        $this->tenantRow('audit_entry', [
            'entity_class' => $class, 'entity_id' => (string) $id, 'action' => $action, 'changes' => json_encode($changes, \JSON_THROW_ON_ERROR),
            'user_id' => $user, 'occurred_at' => self::AT, 'correlation_id' => $label,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tenantRow(string $table, array $row): void
    {
        $this->insert($table, ['tenant_id' => self::TENANT] + $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): void
    {
        $quoted = [];
        $types = [];
        foreach ($row as $column => $value) {
            $name = $this->db->quoteSingleIdentifier($column);
            $quoted[$name] = $value;
            $types[$name] = match (true) {
                \is_bool($value) => ParameterType::BOOLEAN,
                \is_int($value) => ParameterType::INTEGER,
                null === $value => ParameterType::NULL,
                default => ParameterType::STRING,
            };
        }
        $this->db->insert($table, $quoted, $types);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function changes(string $label): array
    {
        $changes = json_decode($this->text('SELECT changes FROM audit_entry WHERE correlation_id = ?', [$label]), true);
        self::assertIsArray($changes);

        return $changes;
    }

    /**
     * @param list<string> $params
     */
    private function text(string $sql, array $params = []): string
    {
        $value = $this->db->fetchOne($sql, $params);
        self::assertIsString($value, $sql);

        return $value;
    }

    /**
     * @return list<string> the values of a text column, each once
     */
    private function distinct(string $sql): array
    {
        $values = [];
        foreach ($this->db->fetchFirstColumn($sql) as $value) {
            self::assertIsString($value, $sql);
            $values[$value] = true;
        }

        return array_keys($values);
    }

    /**
     * @param list<string> $params
     */
    private function rows(string $sql, array $params = []): int
    {
        $rows = $this->db->fetchOne($sql, $params);
        self::assertIsNumeric($rows);

        return (int) $rows;
    }

    private function connect(): Connection
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
        if (!\is_string($url) || !str_starts_with($url, 'postgres')) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])->parse($url);
        unset($params['serverVersion'], $params['charset']);
        $connection = DriverManager::getConnection($params);
        $connection->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        $connection->executeStatement('CREATE SCHEMA '.self::SCHEMA);
        $connection->executeStatement('SET search_path TO '.self::SCHEMA);

        return $connection;
    }

    private function onPostgres(): bool
    {
        return $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function enableForeignKeys(): void
    {
        if (!$this->onPostgres()) {
            $this->db->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

    private function migrateTo(?string $version): void
    {
        $this->plan($this->migrations(), new MigratorConfiguration(), $version);
    }

    private function migrations(): DependencyFactory
    {
        // A fresh factory per run: executed migration instances are frozen.
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => \dirname(__DIR__, 2).'/migrations'],
            ]),
            new ExistingConnection($this->db),
        );

        return $migrations;
    }

    private function plan(DependencyFactory $migrations, MigratorConfiguration $configuration, ?string $version = null): void
    {
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
            null === $version ? $migrations->getVersionAliasResolver()->resolveVersionAlias('latest') : new Version($version),
        );
        $migrations->getMigrator()->migrate($plan, $configuration);
    }
}
