<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /** The messages are ICU MessageFormat: an unbalanced brace or a broken plural would only fail when the page is rendered. */
    public function testEveryMessageIsValidIcu(): void
    {
        foreach (['cs', 'en'] as $locale) {
            foreach ($this->flatten($this->load($locale)) as $key => $text) {
                \MessageFormatter::create($locale, $text);
                // Warnings (negative codes) such as falling back to the default locale data are fine.
                self::assertFalse(intl_is_failure(intl_get_error_code()), sprintf('%s.%s is not valid ICU: %s', $locale, $key, intl_get_error_message()));
            }
        }
    }

    /** A message that names an argument in one language and not in the other renders an empty or missing value. */
    public function testBothLanguagesTakeTheSameArguments(): void
    {
        $english = $this->flatten($this->load('en'));
        foreach ($this->flatten($this->load('cs')) as $key => $text) {
            self::assertArrayHasKey($key, $english);
            self::assertSame(
                $this->arguments($english[$key]),
                $this->arguments($text),
                sprintf('%s takes different arguments in cs and en.', $key),
            );
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function patterns(): iterable
    {
        yield 'plain text' => ['No arguments here', []];
        yield 'simple argument' => ['Select row {label}', ['label']];
        yield 'spaces inside braces' => ['Hello { name }', ['name']];
        yield 'typed argument' => ['Total {amount, number, ::currency/EUR}', ['amount']];
        yield 'plural with a number sign' => ['{count, plural, one {# day} other {# days}}', ['count']];
        yield 'plural branches are text, not arguments' => ['{count, plural, one {item} other {items}}', ['count']];
        yield 'argument inside a branch' => ['{count, plural, one {{name} has # day} other {{name} has # days}}', ['count', 'name']];
        yield 'select' => ['{kind, select, a {Alpha} other {{who} other}}', ['kind', 'who']];
        yield 'quoted braces are literal' => ["'{'count'}' record selected", []];
        yield 'doubled apostrophe' => ["It''s {name}", ['name']];
        yield 'repeated argument' => ['{a} and {a} and {b}', ['a', 'b']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('patterns')]
    public function testArgumentsAreReadFromAnIcuPattern(string $pattern, array $expected): void
    {
        self::assertSame($expected, $this->arguments($pattern));
    }

    /**
     * The names of the arguments of an ICU pattern, sorted.
     *
     * @return list<string>
     */
    private function arguments(string $pattern): array
    {
        $names = [];
        $this->scan($pattern, 0, $names);
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Reads message text from $position up to the brace that closes it, and
     * returns the position of that brace. Apostrophes quote braces as ICU does.
     *
     * @param list<string> $names
     */
    private function scan(string $pattern, int $position, array &$names): int
    {
        $length = \strlen($pattern);
        while ($position < $length) {
            $char = $pattern[$position];
            $next = $pattern[$position + 1] ?? '';
            if ("'" === $char && "'" === $next) {
                $position += 2;
            } elseif ("'" === $char && ('{' === $next || '}' === $next)) {
                $end = strpos($pattern, "'", $position + 1);
                $position = false === $end ? $length : $end + 1;
            } elseif ('}' === $char) {
                return $position;
            } elseif ('{' === $char) {
                $position = $this->scanArgument($pattern, $position + 1, $names);
            } else {
                ++$position;
            }
        }

        return $position;
    }

    /**
     * Reads one argument after its opening brace and returns the position after its closing brace.
     *
     * @param list<string> $names
     */
    private function scanArgument(string $pattern, int $position, array &$names): int
    {
        $length = \strlen($pattern);
        // "name", "name, type" or "name, type, style", up to the closing brace or the first branch.
        $head = '';
        while ($position < $length && '}' !== $pattern[$position] && '{' !== $pattern[$position]) {
            $head .= $pattern[$position];
            ++$position;
        }
        $fields = array_map(trim(...), explode(',', $head));
        $names[] = $fields[0];

        if (\in_array($fields[1] ?? '', ['plural', 'select', 'selectordinal'], true)) {
            // Branches: a selector such as "one" or "offset:1", then text in braces that may hold arguments of its own.
            while ($position < $length && '}' !== $pattern[$position]) {
                $position = '{' === $pattern[$position] ? $this->scan($pattern, $position + 1, $names) + 1 : $position + 1;
            }
        } else {
            while ($position < $length && '}' !== $pattern[$position]) {
                ++$position;
            }
        }

        return $position + 1;
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
