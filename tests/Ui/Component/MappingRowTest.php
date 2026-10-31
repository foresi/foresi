<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class MappingRowTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return array<string, string|array<string, string>>
     */
    private function options(): array
    {
        return ['' => 'Do not import', 'General' => ['name' => 'Name', 'email' => 'E-mail'], 'Billing address' => ['billing_city' => 'City']];
    }

    public function testTheColumnHeaderNamesTheSelectWithGroupedFields(): void
    {
        $crawler = $this->renderTwigComponent('Ui:MappingRow', [
            'id' => 'column-0',
            'name' => 'columns[0]',
            'label' => 'E-mailová adresa',
            'samples' => ['testing@example.com', 'office@example.com'],
            'options' => $this->options(),
            'value' => 'email',
            'badge' => ['label' => 'Same name', 'tone' => 'green'],
        ])->crawler();

        self::assertSame('column-0', $crawler->filter('label')->attr('for'));
        self::assertSame('E-mailová adresa', $crawler->filter('label')->attr('title'));
        self::assertSame('columns[0]', $crawler->filter('select#column-0')->attr('name'));
        self::assertSame(['General', 'Billing address'], $crawler->filter('optgroup')->each(static fn ($group): ?string => $group->attr('label')));
        self::assertSame('Do not import', $crawler->filter('select > option')->text());
        self::assertSame('email', $crawler->filter('option[selected]')->attr('value'));
        self::assertSame('Same name', $crawler->filter('.bg-badge-green')->text());
        self::assertSame(['testing@example.com', 'office@example.com'], $crawler->filter('p [title]')->each(static fn ($sample): string => $sample->text()));
    }

    public function testShowsAtMostThreeNonEmptySamplesAndNoBadgeWithoutAReason(): void
    {
        $crawler = $this->renderTwigComponent('Ui:MappingRow', [
            'id' => 'column-1',
            'name' => 'columns[1]',
            'label' => 'Note',
            'samples' => ['', 'one', '  ', 'two', 'three', 'four'],
            'options' => $this->options(),
        ])->crawler();

        self::assertSame(['one', 'two', 'three'], $crawler->filter('p [title]')->each(static fn ($sample): string => $sample->text()));
        self::assertCount(0, $crawler->filter('[class*="bg-badge-"]'));
        self::assertSame('', $crawler->filter('option[selected]')->attr('value'));
    }

    public function testAColumnWithoutSamplesShowsADash(): void
    {
        $crawler = $this->renderTwigComponent('Ui:MappingRow', ['id' => 'c', 'name' => 'c', 'label' => 'Column 7', 'options' => ['' => 'Do not import']])->crawler();

        self::assertSame('—', $crawler->filter('p span[aria-hidden="true"]')->text());
    }

    public function testRejectsMissingOptionsAndAnEmptyGroup(): void
    {
        foreach ([[], ['Group' => []]] as $options) {
            try {
                $this->renderTwigComponent('Ui:MappingRow', ['id' => 'c', 'name' => 'c', 'label' => 'C', 'options' => $options]);
                self::fail('The mapping row should refuse these options.');
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('should refuse', $error->getMessage());
            }
        }
    }
}
