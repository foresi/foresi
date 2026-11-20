<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

/**
 * The global migration history that the module baselines replaced (ADR 0034):
 * the versions a database migrated before the module packages has executed,
 * every one of them, and the version of the baselines that take their place.
 * app:migrations:rebase swaps the one for the other. Once no database is left
 * on the old history, this class and the command go.
 */
final class GlobalMigrationHistory
{
    /**
     * The short class name every module's baseline carries, so all of them
     * share one timestamp and run in the order of the module graph.
     */
    public const string BASELINE = 'Version20261120000000';

    /**
     * The versions of the global history, oldest first.
     */
    public const array VERSIONS = [
        'DoctrineMigrations\Version20261029000001',
        'DoctrineMigrations\Version20261029000002',
        'DoctrineMigrations\Version20261029000003',
        'DoctrineMigrations\Version20261029000004',
        'DoctrineMigrations\Version20261029000005',
        'DoctrineMigrations\Version20261029000006',
        'DoctrineMigrations\Version20261029000007',
        'DoctrineMigrations\Version20261029000008',
        'DoctrineMigrations\Version20261029000009',
        'DoctrineMigrations\Version20261029000010',
        'DoctrineMigrations\Version20261029000011',
        'DoctrineMigrations\Version20261030000001',
        'DoctrineMigrations\Version20261030000002',
        'DoctrineMigrations\Version20261030000003',
        'DoctrineMigrations\Version20261030000004',
        'DoctrineMigrations\Version20261031000001',
        'DoctrineMigrations\Version20261031000002',
        'DoctrineMigrations\Version20261031000003',
        'DoctrineMigrations\Version20261031000004',
        'DoctrineMigrations\Version20261101000001',
        'DoctrineMigrations\Version20261101000002',
        'DoctrineMigrations\Version20261101000003',
        'DoctrineMigrations\Version20261101000004',
        'DoctrineMigrations\Version20261101000005',
        'DoctrineMigrations\Version20261102000001',
        'DoctrineMigrations\Version20261102000002',
        'DoctrineMigrations\Version20261102000003',
        'DoctrineMigrations\Version20261102000004',
        'DoctrineMigrations\Version20261102000005',
        'DoctrineMigrations\Version20261102000006',
        'DoctrineMigrations\Version20261102000007',
        'DoctrineMigrations\Version20261102000008',
        'DoctrineMigrations\Version20261102000090',
        'DoctrineMigrations\Version20261103000801',
        'DoctrineMigrations\Version20261103000802',
        'DoctrineMigrations\Version20261103000803',
        'DoctrineMigrations\Version20261103000804',
        'DoctrineMigrations\Version20261103000805',
        'DoctrineMigrations\Version20261103020001',
        'DoctrineMigrations\Version20261103020002',
        'DoctrineMigrations\Version20261103030001',
        'DoctrineMigrations\Version20261103030002',
        'DoctrineMigrations\Version20261103060001',
        'DoctrineMigrations\Version20261103080001',
        'DoctrineMigrations\Version20261103090001',
        'DoctrineMigrations\Version20261105020001',
        'DoctrineMigrations\Version20261105030001',
        'DoctrineMigrations\Version20261105040001',
        'DoctrineMigrations\Version20261105070001',
        'DoctrineMigrations\Version20261105080001',
        'DoctrineMigrations\Version20261105090001',
        'DoctrineMigrations\Version20261105100001',
        'DoctrineMigrations\Version20261106010001',
        'DoctrineMigrations\Version20261106020001',
        'DoctrineMigrations\Version20261106030001',
        'DoctrineMigrations\Version20261106040001',
        'DoctrineMigrations\Version20261106050001',
        'DoctrineMigrations\Version20261106060001',
        'DoctrineMigrations\Version20261106070001',
        'DoctrineMigrations\Version20261106080001',
        'DoctrineMigrations\Version20261106090001',
        'DoctrineMigrations\Version20261107090001',
        'DoctrineMigrations\Version20261107100001',
        'DoctrineMigrations\Version20261107110001',
        'DoctrineMigrations\Version20261107120001',
        'DoctrineMigrations\Version20261108120001',
        'DoctrineMigrations\Version20261108130001',
        'DoctrineMigrations\Version20261108140001',
        'DoctrineMigrations\Version20261109120001',
        'DoctrineMigrations\Version20261109130001',
        'DoctrineMigrations\Version20261109150001',
        'DoctrineMigrations\Version20261109160001',
        'DoctrineMigrations\Version20261110100001',
        'DoctrineMigrations\Version20261110120001',
        'DoctrineMigrations\Version20261110140001',
        'DoctrineMigrations\Version20261110200001',
        'DoctrineMigrations\Version20261110200002',
        'DoctrineMigrations\Version20261110210001',
        'DoctrineMigrations\Version20261110220001',
        'DoctrineMigrations\Version20261111000001',
        'DoctrineMigrations\Version20261112000001',
        'DoctrineMigrations\Version20261112000002',
        'DoctrineMigrations\Version20261113000001',
        'DoctrineMigrations\Version20261113000002',
        'DoctrineMigrations\Version20261114000001',
        'DoctrineMigrations\Version20261117000001',
        'DoctrineMigrations\Version20261117200001',
        'DoctrineMigrations\Version20261117210001',
        'DoctrineMigrations\Version20261118000001',
        'DoctrineMigrations\Version20261118000002',
        'DoctrineMigrations\Version20261118000003',
        'DoctrineMigrations\Version20261118000004',
        'DoctrineMigrations\Version20261118000006',
        'DoctrineMigrations\Version20261118000007',
        'DoctrineMigrations\Version20261118100001',
        'DoctrineMigrations\Version20261118200001',
        'DoctrineMigrations\Version20261118210001',
        'DoctrineMigrations\Version20261118300001',
        'DoctrineMigrations\Version20261118400001',
        'DoctrineMigrations\Version20261118610001',
        'DoctrineMigrations\Version20261118620001',
        'DoctrineMigrations\Version20261119100001',
        'DoctrineMigrations\Version20261119200001',
        'DoctrineMigrations\Version20261119410001',
        'DoctrineMigrations\Version20261119700001',
        'DoctrineMigrations\Version20261120100001',
        'DoctrineMigrations\Version20261120200001',
        'DoctrineMigrations\Version20261120300001',
    ];
}
