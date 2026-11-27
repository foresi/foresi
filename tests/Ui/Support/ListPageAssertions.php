<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Shared\Application\View\ListDefinitionSource;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What every table that ListTable builds shows (docs/design/list-pages.md, rule 8): its list
 * with the shipped views app:views:sync stores, the toolbar with a Settings sheet whose Reset
 * forgets the remembered display, widths a person drags, and the builder's Apply turned into
 * the working filter.
 */
trait ListPageAssertions
{
    /**
     * The list a source gives, built once: its own fields, each choice of an enum field named by a
     * translated label, and its shipped views in their order, each counting its rows or not.
     *
     * @param list<string>        $fields the keys of the fields the list is filtered by, in their order
     * @param array<string, bool> $views  the slugs of the shipped views, in their order, and whether each shows a count
     */
    private static function assertListWithViews(ListDefinitionSource $source, string $key, array $fields, array $views): void
    {
        $lists = [...$source->lists()];
        Assert::assertCount(1, $lists);
        $list = $lists[0];
        Assert::assertSame([$list], [...$source->lists()], 'The list is built once.');
        Assert::assertSame($key, $list->key);
        Assert::assertSame($fields, array_values(array_map(static fn (Field $field): string => $field->key, $list->fields)));
        $translator = self::getContainer()->get(TranslatorInterface::class);
        foreach ($list->fields as $field) {
            if (FieldType::Enum !== $field->type) {
                continue;
            }
            Assert::assertSame($field->options, array_keys($field->optionLabels));
            foreach ($field->optionLabels as $label) {
                Assert::assertNotSame($label, $translator->trans($label, [], null, 'en'), $label.' is a translated label.');
            }
        }
        Assert::assertSame(array_keys($views), array_map(static fn (DefaultView $view): string => $view->slug, $list->defaults));
        Assert::assertSame(array_values($views), array_map(static fn (DefaultView $view): bool => $view->showCount, $list->defaults));
        Assert::assertSame(array_keys(array_values($views)), array_map(static fn (DefaultView $view): int => $view->position, $list->defaults));
        // app:views:sync stores the views of every list it finds.
        Assert::assertArrayHasKey($key, self::getContainer()->get(ListDefinitions::class)->all());
    }

    /**
     * The table in $scope has its toolbar, drags its widths to $widths and its Reset forgets the
     * remembered display, keeping the view.
     *
     * @param array<array-key, mixed> $reset the query of the Reset of the Settings sheet
     */
    private static function assertListTable(Crawler $crawler, string $scope, array $reset, string $widths = 'widths'): void
    {
        $table = $crawler->filter($scope);
        Assert::assertCount(1, $table->filter('[data-list-toolbar]'));
        Assert::assertSame($widths, $table->filter('[data-column-resize-param-value]')->attr('data-column-resize-param-value'));
        Assert::assertSame($reset, self::queryOf((string) $table->filter('[data-column-picker-reset]')->attr('href')));
    }

    /**
     * The builder's Apply of a "name contains" row redirects to the working filter, and the page
     * it leads to.
     *
     * @param string $slot the table's slot, '' for the page's first table
     */
    private static function applyBuilder(KernelBrowser $client, string $path, string $field, string $value, string $slot = ''): Crawler
    {
        $rows = ['conditions' => '1', 'c' => [['field' => $field, 'op' => 'contains', 'value' => $value]]];
        $client->request('GET', $path.(str_contains($path, '?') ? '&' : '?').http_build_query('' === $slot ? $rows : [$slot => $rows]));
        Assert::assertSame(303, $client->getResponse()->getStatusCode());
        $query = self::queryOf((string) $client->getResponse()->headers->get('Location'));
        $own = '' === $slot ? $query : $query[$slot];
        Assert::assertIsArray($own);
        Assert::assertArrayNotHasKey('c', $own);
        Assert::assertArrayNotHasKey('conditions', $own);
        Assert::assertArrayHasKey('f', $own);

        return $client->followRedirect();
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return $query;
    }
}
