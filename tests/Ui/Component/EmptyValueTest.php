<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class EmptyValueTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testAMissingValueSaysSoInWordsAScreenReaderReads(): void
    {
        $badge = new Crawler((string) $this->renderTwigComponent('Ui:EmptyValue'))->filter('[data-empty-value]');

        self::assertSame('Nezadáno', $badge->text());
        self::assertNull($badge->attr('aria-hidden'));
    }

    public function testItIsOutlinedNotFilledSoItNeverReadsAsAStatus(): void
    {
        $class = (string) new Crawler((string) $this->renderTwigComponent('Ui:EmptyValue'))->filter('[data-empty-value]')->attr('class');

        self::assertStringContainsString('border-dashed', $class);
        self::assertStringContainsString('text-muted', $class);
        self::assertStringNotContainsString('bg-', $class);
    }

    public function testItTakesWordsOfItsOwn(): void
    {
        self::assertSame('No response', new Crawler((string) $this->renderTwigComponent('Ui:EmptyValue', ['label' => 'No response']))->filter('[data-empty-value]')->text());
    }
}
