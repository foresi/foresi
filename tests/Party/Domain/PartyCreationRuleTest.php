<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use PHPUnit\Framework\TestCase;

/**
 * A party gets its billing address only through CreateParty, so no other
 * production code may construct one.
 */
final class PartyCreationRuleTest extends TestCase
{
    public function testOnlyCreatePartyConstructsParties(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), 'new Party(')) {
                $offenders[] = basename($file->getPathname());
            }
        }

        self::assertSame(['CreateParty.php'], $offenders);
    }
}
