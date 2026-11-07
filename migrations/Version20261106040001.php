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
 * Two-factor sign-in: the sealed TOTP secret, the hashes of the unused recovery
 * codes and the version that invalidates trusted browsers on a reset. Existing
 * users start without it and enrol on their next sign-in.
 */
final class Version20261106040001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two-factor sign-in of users';
    }

    public function up(Schema $schema): void
    {
        $user = $schema->getTable('app_user');
        $user->addColumn('totp_secret_sealed', Types::TEXT, ['notnull' => false]);
        $user->addColumn('recovery_code_hashes', Types::JSON, ['default' => '[]']);
        $user->addColumn('trusted_device_version', Types::INTEGER, ['default' => 0]);
    }

    public function down(Schema $schema): void
    {
        $user = $schema->getTable('app_user');
        $user->dropColumn('totp_secret_sealed');
        $user->dropColumn('recovery_code_hashes');
        $user->dropColumn('trusted_device_version');
    }
}
