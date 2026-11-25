<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Unit;

use Foresi\AiReader\Application\AiReaderSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiReaderSettingsTest extends TestCase
{
    private const string KEY = 'test-key-not-real';

    public function testNotConfiguredWithoutKey(): void
    {
        self::assertFalse(new AiReaderSettings('claude', '', '', '')->isConfigured());
        self::assertFalse(new AiReaderSettings('claude', '  ', '', '')->isConfigured());
    }

    public function testNotConfiguredWithoutAKnownProvider(): void
    {
        self::assertFalse(new AiReaderSettings('', self::KEY, '', '')->isConfigured());
        self::assertFalse(new AiReaderSettings('other', self::KEY, '', '')->isConfigured());
        self::assertSame('other', new AiReaderSettings('Other ', self::KEY, '', '')->provider());
    }

    public function testConfiguredWithClaudeAndAKey(): void
    {
        $settings = new AiReaderSettings(' Claude ', ' '.self::KEY.' ', '', '');

        self::assertTrue($settings->isConfigured());
        self::assertSame('claude', $settings->provider());
        self::assertSame(self::KEY, $settings->apiKey());
    }

    public function testModelDefaultsWhenEmpty(): void
    {
        self::assertSame('claude-sonnet-5-5', new AiReaderSettings('claude', self::KEY, '', '')->model());
        self::assertSame('claude-sonnet-5-5', new AiReaderSettings('claude', self::KEY, '  ', '')->model());
        self::assertSame('claude-other', new AiReaderSettings('claude', self::KEY, ' claude-other ', '')->model());
    }

    public function testTriggerDefaultsToArrival(): void
    {
        self::assertTrue(new AiReaderSettings('claude', self::KEY, '', '')->automatic());
        self::assertTrue(new AiReaderSettings('claude', self::KEY, '', 'arrival')->automatic());
        self::assertTrue(new AiReaderSettings('claude', self::KEY, '', ' Arrival ')->automatic());
        self::assertFalse(new AiReaderSettings('claude', self::KEY, '', 'click')->automatic());
    }

    public function testAnUnknownTriggerLeavesTheReaderOffAndNeverThrows(): void
    {
        $settings = new AiReaderSettings('claude', self::KEY, '', 'sometimes');

        self::assertFalse($settings->isConfigured());
        self::assertFalse($settings->triggerIsValid());
        self::assertTrue(new AiReaderSettings('claude', self::KEY, '', 'click')->triggerIsValid());
        self::assertTrue(new AiReaderSettings('claude', self::KEY, '', '')->isConfigured());
    }

    public function testAnUnknownTriggerNeverBreaksAnInstanceWithoutAKey(): void
    {
        self::assertFalse(new AiReaderSettings('', '', '', 'sometimes')->isConfigured());
        self::assertFalse(new AiReaderSettings('claude', '', '', 'sometimes')->isConfigured());
        self::assertFalse(new AiReaderSettings('other', self::KEY, '', 'sometimes')->isConfigured());
        self::assertTrue(new AiReaderSettings('claude', '', '', 'sometimes')->triggerIsValid());
    }

    /**
     * @return iterable<string, array{callable(AiReaderSettings): string}>
     */
    public static function dumps(): iterable
    {
        yield 'var_export' => [static fn (AiReaderSettings $settings): string => var_export($settings, true)];
        yield 'print_r' => [static fn (AiReaderSettings $settings): string => print_r($settings, true)];
        yield 'var_dump' => [static function (AiReaderSettings $settings): string {
            ob_start();
            var_dump($settings);

            return (string) ob_get_clean();
        }];
        yield 'json_encode' => [static fn (AiReaderSettings $settings): string => json_encode($settings, \JSON_THROW_ON_ERROR)];
        yield 'array cast' => [static fn (AiReaderSettings $settings): string => print_r((array) $settings, true)];
        yield 'debug info' => [static fn (AiReaderSettings $settings): string => print_r($settings->__debugInfo(), true)];
    }

    /**
     * @param callable(AiReaderSettings): string $dump
     */
    #[DataProvider('dumps')]
    public function testKeyNeverInDebugOutput(callable $dump): void
    {
        $settings = new AiReaderSettings('claude', self::KEY, '', '');

        self::assertStringNotContainsString(self::KEY, $dump($settings));
    }

    public function testDebugInfoSaysWhetherItIsConfigured(): void
    {
        self::assertSame(
            ['provider' => 'claude', 'model' => 'claude-sonnet-5-5', 'automatic' => false, 'configured' => true],
            new AiReaderSettings('claude', self::KEY, '', 'click')->__debugInfo(),
        );
    }

    public function testKeyIsNotSerialized(): void
    {
        $this->expectException(\Exception::class);

        serialize(new AiReaderSettings('claude', self::KEY, '', ''));
    }
}
