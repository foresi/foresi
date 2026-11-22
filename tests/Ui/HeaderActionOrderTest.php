<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Guards the order of every page and sheet header: the primary action first, the "⋯" menu last.
 *
 * Ui:MoreActions renders that order itself (its "primary" block, the secondary buttons, the menu),
 * so a header's actions end with Ui:MoreActions and nothing may follow it, and the "⋯" of a header
 * is always Ui:MoreActions, never a bare Ui:Menu. A header's actions are the "actions" block of a
 * page (Ui:PageHeader on the page, the sheet header for a record) and the partials it includes.
 */
final class HeaderActionOrderTest extends TestCase
{
    private const array PARTIALS = ['@Work/projects/_header_actions.html.twig'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headers(): iterable
    {
        $root = \dirname(__DIR__, 2);
        $directories = [];
        $modules = glob($root.'/src/*/templates', \GLOB_ONLYDIR);
        foreach (false === $modules ? [] : $modules as $directory) {
            $directories['@'.basename(\dirname($directory)).'/'] = $directory;
        }
        foreach ($directories as $namespace => $directory) {
            yield from self::headersIn($directory, $namespace);
        }
    }

    /**
     * The headers of the templates in one directory, named as Twig names them.
     *
     * @return iterable<string, array{string, string}>
     */
    private static function headersIn(string $directory, string $namespace): iterable
    {
        foreach (new Finder()->files()->in($directory)->name('*.html.twig')->notPath('components/')->sortByName() as $file) {
            $path = $namespace.$file->getRelativePathname();
            $source = $file->getContents();
            if (\in_array($path, self::PARTIALS, true)) {
                yield $path => [$path, $source];

                continue;
            }
            if (1 === preg_match('/\{%-?\s*block actions\s*-?%\}(.*?)\{%-?\s*endblock\s*-?%\}/s', $source, $match)) {
                yield $path => [$path, $match[1]];
            }
        }
    }

    #[DataProvider('headers')]
    public function testTheOverflowMenuIsTheLastAction(string $path, string $actions): void
    {
        $close = strrpos($actions, '</twig:Ui:MoreActions>');
        if (false === $close) {
            self::assertStringNotContainsString('<twig:Ui:Menu', $actions, $path.': a header\'s "⋯" is Ui:MoreActions, not a bare Ui:Menu.');

            return;
        }
        $after = preg_replace(['/\{%.*?%\}/s', '/\{#.*?#\}/s'], '', substr($actions, $close + \strlen('</twig:Ui:MoreActions>')));
        self::assertSame('', trim((string) $after), $path.': nothing follows the "⋯" menu; put the primary action in the "primary" block of Ui:MoreActions.');
        self::assertDoesNotMatchRegularExpression('/<twig:Ui:Menu[\s>]/', $actions, $path.': a header\'s "⋯" is Ui:MoreActions, not a bare Ui:Menu.');
    }
}
