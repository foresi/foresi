<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class BulkBarTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testToolbarIsHiddenUntilSelectionAndStatusRegionIsAlwaysPresent(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:BulkBar', [], '<button>Archive</button>');

        self::assertMatchesRegularExpression('#<div[^>]*role="status"[^>]*aria-live="polite"|<div[^>]*aria-live="polite"[^>]*role="status"#', $html);
        self::assertMatchesRegularExpression('#<div[^>]*role="toolbar"[^>]*hidden|<div[^>]*hidden[^>]*role="toolbar"#', $html);
        self::assertStringContainsString('<button>Archive</button>', $html);
        self::assertStringContainsString('aria-label="Hromadné akce"', $html);
    }

    public function testCarriesCountTemplatesForClientSideAnnouncement(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:BulkBar');

        self::assertStringContainsString('data-label-one="Vybrán {count} záznam"', $html);
        self::assertStringContainsString('data-label-few="Vybrány {count} záznamy"', $html);
        self::assertStringContainsString('data-label-other="Vybráno {count} záznamů"', $html);
        self::assertStringContainsString('data-table-selection-target="count"', $html);
        self::assertStringContainsString('data-table-selection-target="status"', $html);
    }
}
