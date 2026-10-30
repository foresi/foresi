<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\Icons\IconRendererInterface;

final class AssetsTest extends WebTestCase
{
    public function testLayoutLoadsNoExternalResources(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertIsString($html);

        $references = [];
        foreach ([
            '/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i',
            '/<link\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i',
            '/@import\s+(?:url\()?["\']?([^"\')\s;]+)/i',
            '/<(?:img|iframe|source)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i',
        ] as $pattern) {
            preg_match_all($pattern, $html, $matches);
            $references = [...$references, ...$matches[1]];
        }

        self::assertNotSame([], $references, 'The layout is expected to reference its own assets.');
        foreach ($references as $reference) {
            self::assertDoesNotMatchRegularExpression('#^(?:https?:)?//(?!localhost(?:[:/]|$))#i', $reference, 'External resource: '.$reference);
        }
    }

    public function testImportmapPointsOnlyToLocalFiles(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        $html = $client->getResponse()->getContent();
        self::assertIsString($html);
        // Test env ignores missing imports, so prove that the real entries resolve to local files.
        self::assertMatchesRegularExpression('#"@hotwired/turbo":\s*"/assets/vendor/@hotwired/turbo/turbo\.index-[^"]+\.js"#', $html);
        self::assertMatchesRegularExpression('#"@hotwired/stimulus":\s*"/assets/vendor/@hotwired/stimulus/stimulus\.index-[^"]+\.js"#', $html);
        self::assertMatchesRegularExpression('#"@symfony/stimulus-bundle":\s*"/assets/@symfony/stimulus-bundle/loader-[^"]+\.js"#', $html);
        self::assertStringNotContainsString('jspm.io', $html);
        self::assertStringNotContainsString('jsdelivr', $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function testLockedIconsAreAvailableOffline(): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(IconRendererInterface::class);

        $icons = ['layout-grid', 'square-check-big', 'clock-3', 'target', 'file-pen-line', 'file-signature', 'receipt-text', 'inbox', 'landmark', 'chart-column', 'puzzle', 'users', 'settings', 'search', 'chevrons-up-down', 'plus', 'upload', 'download', 'list-filter', 'arrow-up-down', 'columns-3', 'share-2', 'send', 'circle-check', 'tag', 'ellipsis', 'x', 'chevron-left', 'chevron-right', 'chevron-down', 'pencil', 'file-down', 'link-2', 'history', 'user', 'key-round', 'languages', 'building-2', 'log-out', 'calendar', 'plug', 'archive', 'archive-restore', 'refresh-cw', 'triangle-alert', 'user-plus'];

        foreach ($icons as $icon) {
            self::assertFileExists(\dirname(__DIR__, 2).sprintf('/assets/icons/lucide/%s.svg', $icon));
            // Rendering never falls back to the online Iconify API: it is disabled.
            self::assertStringStartsWith('<svg', $renderer->renderIcon('lucide:'.$icon));
        }
    }
}
