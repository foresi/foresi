<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Component\ActionBar;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ActionBarTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testIsDockedToTheBottomAndCarriesTheActionsInOrder(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:ActionBar', [], '<a href="/back">Back</a><button type="submit">Save</button>');

        self::assertStringContainsString('data-action-bar', $html);
        self::assertStringContainsString('data-controller="action-bar"', $html);
        self::assertStringContainsString('sticky', $html);
        self::assertStringContainsString('-bottom-px', $html);
        self::assertStringContainsString('border-t', $html);
        self::assertStringContainsString('bg-white', $html);
        // In normal flow on low screens, where a docked bar would cover most of the page.
        self::assertStringContainsString('[@media(max-height:480px)]:static', $html);
        // The primary action is the last one and sits on the right.
        self::assertStringContainsString('ml-auto', $html);
        self::assertLessThan(strpos($html, 'Save'), strpos($html, 'Back'));
    }

    public function testButtonsSpanTheBarOnNarrowScreens(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:ActionBar', [], '<button>Save</button>');

        self::assertStringContainsString('max-sm:grid-flow-col', $html);
        self::assertStringContainsString('max-sm:[&_button]:w-full', $html);
        self::assertStringContainsString('max-sm:[&_a]:w-full', $html);
    }

    public function testShowsTheStatusTextOnTheLeft(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:ActionBar', ['status' => '20 of 22 columns imported'], '<button>Check</button>');

        self::assertMatchesRegularExpression('#<p[^>]*aria-live="polite"[^>]*>20 of 22 columns imported</p>#', $html);
        self::assertLessThan(strpos($html, 'Check'), strpos($html, '20 of 22'));
    }

    public function testNoStatusNoParagraph(): void
    {
        self::assertStringNotContainsString('data-action-bar-status', (string) $this->renderTwigComponent('Ui:ActionBar', [], '<button>Save</button>'));
        self::assertStringNotContainsString('data-action-bar-status', (string) $this->renderTwigComponent('Ui:ActionBar', ['status' => '  '], '<button>Save</button>'));
    }

    public function testStatusTextIsTrimmedAndBlankMeansNone(): void
    {
        $bar = new ActionBar();
        self::assertNull($bar->statusText());

        $bar->status = '  ';
        self::assertNull($bar->statusText());

        $bar->status = ' Ready ';
        self::assertSame('Ready', $bar->statusText());
    }

    public function testExtraAttributesReachTheBar(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:ActionBar', ['data-test' => 'bar'], '<button>Save</button>');

        self::assertStringContainsString('data-test="bar"', $html);
    }
}
