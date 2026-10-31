<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationsTest extends TestCase
{
    private const int HELP_LENGTH = 60;

    public function testCzechAndEnglishCatalogsHaveTheSameKeys(): void
    {
        self::assertSame($this->keys('cs'), $this->keys('en'));
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (['cs', 'en'] as $locale) {
            foreach ($this->flatten($this->load($locale)) as $key => $value) {
                self::assertNotSame('', trim($value), sprintf('%s.%s is empty.', $locale, $key));
            }
        }
    }

    /** A help text is one short line that helps fill the field: it fits a half-width field on the desktop. */
    public function testEveryHelpTextFitsOneLine(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $help = array_filter($this->flatten($this->load($locale)), static fn (string $key): bool => 1 === preg_match('/[._]help$/', $key), \ARRAY_FILTER_USE_KEY);
            self::assertNotSame([], $help);
            foreach ($help as $key => $text) {
                self::assertLessThanOrEqual(self::HELP_LENGTH, mb_strlen($text), sprintf('%s.%s is longer than one line.', $locale, $key));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale): array
    {
        $keys = array_keys($this->flatten($this->load($locale)));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<mixed>
     */
    private function load(string $locale): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2).sprintf('/translations/messages+intl-icu.%s.yaml', $locale));
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $path)];
            } else {
                self::assertIsString($value, sprintf('%s must be a string.', $path));
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
