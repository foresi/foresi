<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Contacts\Domain;

use PHPUnit\Framework\TestCase;

/**
 * A party gets its billing address only through CreateParty of the contacts
 * module, so no other production code may construct one.
 */
final class PartyCreationRuleTest extends TestCase
{
    public function testOnlyCreatePartyConstructsParties(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 5).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && 1 === preg_match('/new\s+(\\\\?Foresi\\\\Party\\\\Domain\\\\)?Party\s*\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = basename($file->getPathname());
            }
        }

        self::assertSame(['CreateParty.php'], $offenders);
    }
}
