<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Avatar;
use Foresi\Ui\Contract\PersonPhotos;
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
        $this->mountTwigComponent('Ui:Avatar', ['name' => 'Jana', 'size' => 'xxl']);
    }

    public function testAPhotoFillsTheCircleInPlaceOfTheInitials(): void
    {
        $avatar = $this->avatar(['name' => 'Jana Example', 'photo' => '/people/1/photo?v=abc', 'size' => 'xl']);

        $image = $avatar->filter('img[data-avatar-photo]');
        self::assertSame('/people/1/photo?v=abc', $image->attr('src'));
        self::assertSame('', $image->attr('alt'));
        self::assertStringContainsString('object-cover', (string) $image->attr('class'));
        self::assertStringNotContainsString('JE', $avatar->text());
        self::assertStringContainsString('size-24', (string) $avatar->attr('class'));
        self::assertStringNotContainsString(Avatar::tone('Jana Example'), (string) $avatar->attr('class'));
    }

    public function testAUserWithAPhotoIsShownByItAndAUserWithoutOneByTheInitials(): void
    {
        self::getContainer()->set(PersonPhotos::class, new class implements PersonPhotos {
            public function url(string $userId): ?string
            {
                return 'user-1' === $userId ? '/people/user-1/photo?v=1' : null;
            }
        });

        $photo = $this->avatar(['name' => 'Jana Example', 'user' => 'user-1']);
        self::assertSame('/people/user-1/photo?v=1', $photo->filter('img[data-avatar-photo]')->attr('src'));
        self::assertNull($photo->attr('user'));

        $initials = $this->avatar(['name' => 'Petr Example', 'user' => 'user-2']);
        self::assertCount(0, $initials->filter('img'));
        self::assertSame('PE', trim($initials->text()));

        // A photo given beats the one of the user; nobody has none.
        self::assertSame('/own.jpg', $this->avatar(['name' => 'Jana Example', 'user' => 'user-1', 'photo' => '/own.jpg'])->filter('img')->attr('src'));
        self::assertCount(0, $this->avatar(['name' => '', 'user' => 'user-1'])->filter('img'));
    }
}
