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

    public function testErrorsAreListedUnderTheSelectAndMarkIt(): void
    {
        $crawler = $this->renderTwigComponent('Ui:MappingRow', [
            'id' => 'column-0',
            'name' => 'columns[0]',
            'label' => 'Firma',
            'options' => $this->options(),
            'errors' => ['Match the field Name to one of the columns', 'Match the field City to one of the columns'],
        ])->crawler();

        $select = $crawler->filter('select');
        self::assertSame('true', $select->attr('aria-invalid'));
        self::assertSame('column-0-error', $select->attr('aria-describedby'));
        self::assertNotNull($select->attr('autofocus'));
        self::assertStringContainsString('border-badge-red-text', (string) $select->attr('class'));
        self::assertStringNotContainsString('border-control-line', (string) $select->attr('class'));
        self::assertSame(
            ['Match the field Name to one of the columns', 'Match the field City to one of the columns'],
            $crawler->filter('#column-0-error p')->each(static fn ($message): string => $message->text()),
        );
    }

    public function testARowWithoutErrorsIsNotMarked(): void
    {
        $crawler = $this->renderTwigComponent('Ui:MappingRow', ['id' => 'column-0', 'name' => 'columns[0]', 'label' => 'Firma', 'options' => $this->options()])->crawler();

        $select = $crawler->filter('select');
        self::assertNull($select->attr('aria-invalid'));
        self::assertNull($select->attr('aria-describedby'));
        self::assertNull($select->attr('autofocus'));
        self::assertSame('h-control w-full appearance-none rounded-control border border-control-line bg-white pl-2.5 pr-8 text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green', $select->attr('class'));
        self::assertCount(0, $crawler->filter('#column-0-error'));
    }

    public function testOnlyTheFirstInvalidRowTakesTheFocus(): void
    {
        $row = fn (int $column): \Symfony\Component\DomCrawler\Crawler => $this->renderTwigComponent('Ui:MappingRow', ['id' => 'column-'.$column, 'name' => 'columns['.$column.']', 'label' => 'C'.$column, 'options' => $this->options(), 'errors' => ['Bad']])->crawler();

        self::assertNotNull($row(0)->filter('select')->attr('autofocus'));
        self::assertNull($row(1)->filter('select')->attr('autofocus'));
    }

    public function testRejectsAnEmptyError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:MappingRow', ['id' => 'column-0', 'name' => 'columns[0]', 'label' => 'Firma', 'options' => $this->options(), 'errors' => [' ']]);
    }
}
