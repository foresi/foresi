<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Avatar;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class AvatarTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @param array<string, mixed> $props
     */
    private function avatar(array $props): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:Avatar', $props))->filter('[data-avatar]');
    }

    public function testAPersonIsACircleWithTheInitialsOnTheColourOfTheSeed(): void
    {
        $avatar = $this->avatar(['name' => 'Jana Example', 'seed' => 'user-1']);

        self::assertSame('JE', trim($avatar->text()));
        $class = (string) $avatar->attr('class');
        self::assertStringContainsString('rounded-avatar', $class);
        self::assertStringContainsString(Avatar::tone('user-1'), $class);
        self::assertStringContainsString('size-6', $class);
    }

    public function testWithoutASeedTheNamePicksTheColour(): void
    {
        self::assertStringContainsString(Avatar::tone('Jana Example'), (string) $this->avatar(['name' => 'Jana Example'])->attr('class'));
    }

    public function testBesideTheNameItIsHiddenFromAssistiveTech(): void
    {
        $avatar = $this->avatar(['name' => 'Jana Example']);

        self::assertSame('true', $avatar->attr('aria-hidden'));
        self::assertNull($avatar->attr('role'));
    }

    public function testStandingAloneItIsAnImageNamedByTheFullName(): void
    {
        $avatar = $this->avatar(['name' => 'Jana Example', 'labelled' => true]);

        self::assertSame('img', $avatar->attr('role'));
        self::assertSame('Jana Example', $avatar->attr('aria-label'));
        self::assertNull($avatar->attr('aria-hidden'));
    }

    public function testNobodyIsADashedCircleWithAPersonIcon(): void
    {
        $avatar = $this->avatar(['name' => '', 'labelled' => true]);

        self::assertStringContainsString('border-dashed', (string) $avatar->attr('class'));
        self::assertCount(1, $avatar->filter('svg'));
        self::assertNotEmpty($avatar->attr('aria-label'));
    }

    public function testTheSizesGrow(): void
    {
        self::assertStringContainsString('size-8', (string) $this->avatar(['name' => 'Jana', 'size' => 'md'])->attr('class'));
        self::assertStringContainsString('size-9', (string) $this->avatar(['name' => 'Jana', 'size' => 'lg'])->attr('class'));
    }

    public function testAnUnknownSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Avatar', ['name' => 'Jana', 'size' => 'xl']);
    }
}
