<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\MoreActions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class MoreActionsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const string ITEM = '<a role="menuitem" href="/x">X</a>';

    /**
     * @param array<string, string> $blocks
     */
    private function render(?string $content = null, array $blocks = []): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:MoreActions', [], $content, $blocks));
    }

    public function testNothingRendersWithoutActions(): void
    {
        self::assertCount(0, $this->render()->filter('[data-more-actions]'));
    }

    public function testItemsComeBeforeTheLastGroupBehindADivider(): void
    {
        $crawler = $this->render(self::ITEM, ['last' => '<button role="menuitem">Archive</button>']);

        $trigger = $crawler->filter('[aria-haspopup="menu"]');
        self::assertSame('Další akce', $trigger->attr('aria-label'));
        $groups = $crawler->filter('[role="menu"] > [role="group"]');
        self::assertCount(2, $groups);
        self::assertNotNull($groups->eq(0)->attr('data-more-actions-items'));
        self::assertNotNull($groups->eq(1)->attr('data-more-actions-last'));
        self::assertStringContainsString('border-t', (string) $groups->eq(1)->attr('class'));
        self::assertStringNotContainsString('max-sm:', (string) $groups->eq(1)->attr('class'));
    }

    public function testALoneLastGroupHasNoDivider(): void
    {
        $last = $this->render(null, ['last' => '<button role="menuitem">Delete</button>'])->filter('[data-more-actions-last]');

        self::assertSame('', trim((string) $last->attr('class')));
    }

    public function testButtonsHideOnAPhoneAndTheirRowsShowOnlyThere(): void
    {
        $crawler = $this->render(null, [
            'buttons' => '<a href="/share">Share</a>',
            'phone' => '<a role="menuitem" href="/share">Share</a>',
            'last' => '<button role="menuitem">Delete</button>',
        ]);

        self::assertSame('hidden sm:contents', $crawler->filter('[data-more-actions-buttons]')->attr('class'));
        self::assertSame('contents sm:hidden', $crawler->filter('[data-more-actions-phone]')->attr('class'));
        self::assertStringStartsWith('max-sm:mt-1', (string) $crawler->filter('[data-more-actions-last]')->attr('class'));
        self::assertStringNotContainsString('sm:hidden', (string) $crawler->filter('[data-more-actions-menu]')->attr('class'));
    }

    public function testATriggerWithPhoneRowsOnlyShowsOnlyOnAPhone(): void
    {
        $crawler = $this->render(null, ['buttons' => '<a href="/share">Share</a>', 'phone' => self::ITEM]);

        self::assertStringContainsString('sm:hidden', (string) $crawler->filter('[data-more-actions-menu]')->attr('class'));
    }

    public function testButtonsWithoutRowsRenderNoMenu(): void
    {
        $crawler = $this->render(null, ['buttons' => '<a href="/share">Share</a>']);

        self::assertCount(1, $crawler->filter('[data-more-actions-buttons]'));
        self::assertCount(0, $crawler->filter('[role="menu"]'));
    }

    public function testABlankIdIsRefused(): void
    {
        $actions = new MoreActions();
        $actions->menuId = ' ';
        $this->expectExceptionMessageIsOrContains('A more-actions menu needs an id.');
        $actions->validate();
    }

    public function testAnUnknownSizeIsRefused(): void
    {
        $actions = new MoreActions();
        $actions->menuSize = 'huge';
        $this->expectExceptionMessageIsOrContains('Unknown size "huge"');
        $actions->validate();
    }

    public function testASmallTriggerFollowsTheSheetHeader(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('Ui:MoreActions', ['menuSize' => 'sm'], self::ITEM));

        self::assertStringContainsString('h-control-sm', (string) $crawler->filter('[aria-haspopup="menu"]')->attr('class'));
    }
}
