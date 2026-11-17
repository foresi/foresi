<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance serves one customer (ADR 0035): the tables of the contacts and
 * firms, the people and their API tokens, and the imports lose their tenant
 * column, and so does the primary key of the API idempotency keys. The guard
 * (Version20261117210001) made sure the database holds at most one tenant.
 *
 * The indexes that led with the tenant are rebuilt on their remaining columns
 * under the same names; a person now has one membership, so the unique index
 * of the membership holds the user alone and is named after it, and takes the
 * place of the plain index of the user. The plain tenant indexes go with the
 * column.
 *
 * Going down adds the column back, not null, so it works only while these
 * tables are empty. With data, restore the dump taken before this migration.
 */
final class Version20261118000006 extends AbstractMigration
{
    /** Each table with its plain tenant index, if it has one. */
    private const array TENANT_INDEXES = [
        'party' => 'idx_party_tenant',
        'party_address' => 'idx_party_address_tenant',
        'party_bank_account' => 'idx_party_bank_account_tenant',
        'party_email' => 'idx_party_email_tenant',
        'party_firm_settings' => 'idx_party_firm_settings_tenant',
        'contact_link' => 'idx_contact_link_tenant',
        'firm_bank_account' => 'idx_firm_bank_account_tenant',
        'legal_entity' => 'idx_legal_entity_tenant',
        'membership' => 'idx_membership_tenant',
        'invitation' => 'idx_invitation_tenant',
        'api_token' => 'idx_api_token_tenant',
        'import' => 'idx_import_tenant',
        'import_row' => 'idx_import_row_tenant',
        'import_profile' => 'idx_import_profile_tenant',
        'import_ambiguous_match' => 'idx_import_ambiguous_tenant',
        'import_missing_record' => 'idx_import_missing_tenant',
    ];

    /** The indexes that led with the tenant: table, name before, columns after the tenant, name after, unique. */
    private const array LED_BY_TENANT = [
        ['party', 'idx_party_registration_key', ['registration_key'], 'idx_party_registration_key', false],
        ['party', 'idx_party_vat_id', ['vat_id'], 'idx_party_vat_id', false],
        ['party_email', 'idx_party_email_key', ['address_key'], 'idx_party_email_key', false],
        ['party_firm_settings', 'uniq_party_firm_settings', ['party_id', 'legal_entity_id'], 'uniq_party_firm_settings', true],
        ['contact_link', 'uniq_contact_link_pair', ['company_id', 'person_id'], 'uniq_contact_link_pair', true],
        ['legal_entity', 'uniq_legal_entity_active_registration', ['registration_country', 'active_registration_key'], 'uniq_legal_entity_active_registration', true],
        ['membership', 'uniq_membership_tenant_user', ['user_id'], 'uniq_membership_user', true],
        ['invitation', 'idx_invitation_email', ['email'], 'idx_invitation_email', false],
        ['api_token', 'idx_api_token_user', ['user_id'], 'idx_api_token_user', false],
        ['import_profile', 'idx_import_profile_signature', ['target_key', 'header_signature'], 'idx_import_profile_signature', false],
    ];

    /** The index of the membership's user, which the unique index of the user now covers. */
    private const string MEMBERSHIP_USER = 'idx_86ffd285a76ed395';

    private const string IDEMPOTENCY = 'api_idempotency_key';

    /** The primary key of the idempotency keys after the tenant. */
    private const array IDEMPOTENCY_KEY = ['token_id', 'operation', 'idempotency_key'];

    public function getDescription(): string
    {
        return 'Drop the tenant column from the party, identity and import tables and from the API idempotency keys';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LED_BY_TENANT as [$table, $before]) {
            $schema->getTable($table)->dropIndex($before);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->dropIndex($index);
            $schema->getTable($table)->dropColumn('tenant_id');
        }
        foreach (self::LED_BY_TENANT as [$table, , $columns, $after, $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex($columns, $after)
                : $schema->getTable($table)->addIndex($columns, $after);
        }
        if ($schema->getTable('membership')->hasIndex(self::MEMBERSHIP_USER)) {
            $schema->getTable('membership')->dropIndex(self::MEMBERSHIP_USER);
        }

        $idempotency = $schema->getTable(self::IDEMPOTENCY);
        $idempotency->dropPrimaryKey();
        $idempotency->dropColumn('tenant_id');
        $idempotency->setPrimaryKey(self::IDEMPOTENCY_KEY);
    }

    public function preDown(Schema $schema): void
    {
        foreach ([...array_keys(self::TENANT_INDEXES), self::IDEMPOTENCY] as $table) {
            $this->abortIf(
                false !== $this->connection->fetchOne('SELECT 1 FROM '.$table),
                \sprintf('The table %s holds rows, which have no tenant to go back to: restore the dump taken before this migration instead.', $table),
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('membership')->addIndex(['user_id'], self::MEMBERSHIP_USER);
        foreach (self::LED_BY_TENANT as [$table, , , $after]) {
            $schema->getTable($table)->dropIndex($after);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->addColumn('tenant_id', 'guid', ['notnull' => true]);
            $schema->getTable($table)->addIndex(['tenant_id'], $index);
        }
        foreach (self::LED_BY_TENANT as [$table, $before, $columns, , $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex(['tenant_id', ...$columns], $before)
                : $schema->getTable($table)->addIndex(['tenant_id', ...$columns], $before);
        }

        $idempotency = $schema->getTable(self::IDEMPOTENCY);
        $idempotency->dropPrimaryKey();
        $idempotency->addColumn('tenant_id', 'guid', ['notnull' => true]);
        $idempotency->setPrimaryKey(['tenant_id', ...self::IDEMPOTENCY_KEY]);
    }
}
