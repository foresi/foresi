<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ValueTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testATextIsText(): void
    {
        self::assertSame('Example Company', trim((string) $this->renderTwigComponent('Ui:Value', ['value' => 'Example Company'])));
    }

    public function testABadgeIsABadgeOfItsTone(): void
    {
        $badge = $this->crawler(['value' => ['label' => 'Paid', 'tone' => 'green']])->filter('span.bg-badge-green');

        self::assertSame('Paid', $badge->text());
    }

    public function testBadgesAreOneBadgeEach(): void
    {
        $badges = $this->crawler(['value' => ['badges' => [['label' => 'Customer', 'tone' => 'green'], ['label' => 'Supplier']]]])->filter('[data-badges] > span');

        self::assertSame(['Customer', 'Supplier'], $badges->each(static fn (Crawler $badge): string => $badge->text()));
        self::assertStringContainsString('bg-badge-gray', (string) $badges->eq(1)->attr('class'));
    }

    public function testNothingIsTheEmptyBadge(): void
    {
        foreach ([null, '', [], ['badges' => []]] as $nothing) {
            self::assertSame('Nezadáno', $this->crawler(['value' => $nothing])->filter('[data-empty-value]')->text());
        }
        self::assertSame('No response', $this->crawler(['empty' => 'No response'])->filter('[data-empty-value]')->text());
    }

    public function testLinksAreLinks(): void
    {
        $link = $this->crawler(['value' => ['links' => [['text' => 'billing@example.com', 'href' => 'mailto:billing@example.com']]]])->filter('a');

        self::assertSame('mailto:billing@example.com', $link->attr('href'));
    }

    public function testAnAttributeOfAListIsShownAsItsColumnShowsIt(): void
    {
        $badges = $this->crawler(['list' => 'party.contacts', 'attribute' => 'roles', 'value' => [\Foresi\Party\Domain\PartyRole::Customer]])->filter('[data-badges] > span');

        self::assertSame(['Zákazník'], $badges->each(static fn (Crawler $badge): string => $badge->text()));
        self::assertStringContainsString('bg-badge-green', (string) $badges->attr('class'));
    }

    public function testAListWithoutItsAttributeIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:Value', ['list' => 'party.contacts', 'value' => 'x']);
    }

    public function testAnUnsafeLinkIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:Value', ['value' => ['links' => [['text' => 'x', 'href' => 'javascript:alert(1)']]]]);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function crawler(array $props): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:Value', $props));
    }
}
