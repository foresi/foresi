<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AlertTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function tones(): iterable
    {
        yield 'success' => ['success', 'bg-badge-green text-badge-green-text', 'status'];
        yield 'info' => ['info', 'bg-badge-gray text-badge-gray-text', 'status'];
        yield 'warning' => ['warning', 'bg-badge-amber text-badge-amber-text', 'status'];
        yield 'error' => ['error', 'bg-badge-red text-badge-red-text', 'alert'];
    }

    #[DataProvider('tones')]
    public function testToneClassesAndRole(string $tone, string $classes, string $role): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Alert', ['tone' => $tone], 'Saved');

        self::assertStringContainsString($classes, $html);
        self::assertStringContainsString('role="'.$role.'"', $html);
        self::assertStringContainsString('Saved', $html);
    }

    public function testDefaultToneIsInfo(): void
    {
        self::assertStringContainsString('bg-badge-gray', (string) $this->renderTwigComponent('Ui:Alert', [], 'X'));
    }

    public function testTitleAndIconAreRenderedAboveTheContent(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Alert', ['tone' => 'warning', 'title' => 'Possible duplicate', 'icon' => 'triangle-alert'], 'Body text');

        self::assertMatchesRegularExpression('/Possible duplicate.*Body text/s', $html);
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testPlainAlertHasNoTitleBlock(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Alert', ['tone' => 'success'], 'Saved');

        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('font-semibold', $html);
    }

    public function testExtraAttributesAndRoleOverrideReachTheBox(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Alert', ['tone' => 'error', 'attributes' => ['role' => 'note', 'data-x' => '1', 'class' => 'mt-3']], 'X');

        self::assertStringContainsString('role="note"', $html);
        self::assertStringNotContainsString('role="alert"', $html);
        self::assertStringContainsString('data-x="1"', $html);
        self::assertStringContainsString('mt-3', $html);
    }

    public function testContentIsEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Alert', ['title' => '<b>x</b>'], 'Body');

        self::assertStringNotContainsString('<b>', $html);
    }

    public function testAnActionPostsWithItsTokenAfterTheMessage(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Alert', ['tone' => 'success', 'actionLabel' => 'Undo', 'actionHref' => '/tasks/1/restore', 'actionToken' => 'abc'], 'Moved to the trash.');

        self::assertMatchesRegularExpression('~Moved to the trash\..*<form method="post" action="/tasks/1/restore"[^>]*data-alert-action>.*name="_token" value="abc".*<button[^>]*type="submit"[^>]*>\s*Undo~s', $html);
    }

    public function testAPlainAlertHasNoAction(): void
    {
        self::assertStringNotContainsString('<form', (string) $this->renderTwigComponent('Ui:Alert', ['tone' => 'success'], 'Saved'));
    }

    public function testAnActionNeedsALabelAndAUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Alert', ['actionLabel' => 'Undo']);
    }

    public function testAnActionGoesToAnAllowedUrlOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Alert', ['actionLabel' => 'Undo', 'actionHref' => 'javascript:alert(1)']);
    }

    public function testUnknownToneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Alert', ['tone' => 'purple']);
    }

    public function testUnknownIconThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Alert', ['icon' => 'not-an-icon']);
    }
}
