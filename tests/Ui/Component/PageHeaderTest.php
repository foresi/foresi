<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Component\PageHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PageHeaderTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testTitleIsH1AndCrumbsFormLabelledNavigation(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', [
            'title' => 'Example Company',
            'crumbs' => [['label' => 'Contacts', 'href' => '/contacts'], ['label' => 'Detail']],
        ]);

        self::assertStringContainsString('<h1', $html);
        self::assertStringContainsString('Example Company', $html);
        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Drobečková navigace"', $html);
        self::assertStringContainsString('href="/contacts"', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testOnlyTheLastCrumbIsCurrent(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'T', 'crumbs' => [['label' => 'A'], ['label' => 'B', 'href' => '/b'], ['label' => 'C']]]);

        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('aria-current="page">C<', $html);
    }

    public function testNoCrumbsNoNav(): void
    {
        self::assertStringNotContainsString('<nav', (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'T']));
    }

    public function testBadgeAndActions(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', [
            'title' => 'T',
            'badge' => ['label' => 'Active', 'tone' => 'green'],
        ], '<a href="/edit">Edit</a>');

        self::assertStringContainsString('bg-badge-green', $html);
        self::assertStringContainsString('Active', $html);
        self::assertStringContainsString('<a href="/edit">Edit</a>', $html);
    }

    public function testNoBadgeByDefault(): void
    {
        self::assertStringNotContainsString('bg-badge-', (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'T']));
    }

    public function testLongTitleIsTruncatedWithTitleAttribute(): void
    {
        $title = str_repeat('Long Company Name ', 4);
        $html = (string) $this->renderTwigComponent('Ui:PageHeader', ['title' => $title]);

        self::assertStringContainsString('title="'.$title.'"', $html);
        self::assertStringContainsString('truncate', $html);
    }

    public function testTitleIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', []);
    }

    public function testUnsafeCrumbHrefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'crumbs' => [['label' => 'x', 'href' => 'javascript:alert(1)']]]);
    }

    public function testCrumbWithoutLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'crumbs' => [['href' => '/x']]]);
    }

    public function testInvalidBadgeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'badge' => ['label' => 'x', 'tone' => 'pink']]);
    }

    public function testMoreBadgesFollowTheFirstEachWithItsOptionalIcon(): void
    {
        $crawler = $this->renderTwigComponent('Ui:PageHeader', [
            'title' => 'T',
            'badge' => ['label' => 'Archived', 'tone' => 'gray'],
            'badges' => [['label' => 'Private', 'tone' => 'amber', 'icon' => 'lock'], ['label' => 'Plain', 'tone' => 'green']],
        ])->crawler();

        $extra = $crawler->filter('[data-header-badge]');
        self::assertSame(['Private', 'Plain'], $extra->each(static fn ($badge): string => trim($badge->text())));
        self::assertStringContainsString('bg-badge-amber', (string) $extra->eq(0)->attr('class'));
        self::assertCount(1, $extra->eq(0)->filter('svg'));
        self::assertCount(0, $extra->eq(1)->filter('svg'));
        self::assertSame('Archived', trim($crawler->filter('h1 + span')->text()));
    }

    public function testAnExtraBadgeWithAnIconOutsideTheSetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'badges' => [['label' => 'x', 'tone' => 'gray', 'icon' => 'no-such-icon']]]);
    }

    private const array TITLE_EDIT = ['action' => '/tasks/1/title', 'token' => 't0k', 'name' => 'title', 'id' => 'title', 'label' => 'Title', 'href' => '/tasks/1?edit=title', 'cancelHref' => '/tasks/1'];

    public function testATitleEditedInPlaceIsTheHeadingAndAHiddenForm(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'Wireframes', 'titleEdit' => self::TITLE_EDIT]));

        $edit = $crawler->filter('[data-controller="inline-field"]');
        self::assertSame('Wireframes', $edit->filter('h1')->text());
        self::assertNull($edit->filter('h1')->attr('hidden'));
        self::assertSame('/tasks/1?edit=title', $edit->filter('a[data-page-title-pencil]')->attr('href'));
        self::assertNull($edit->filter('a[data-page-title-pencil]')->attr('hidden'));
        $form = $edit->filter('form[data-page-title-form]');
        self::assertNotNull($form->attr('hidden'));
        self::assertSame('/tasks/1/title', $form->attr('action'));
        self::assertSame('t0k', $form->filter('input[name="_token"]')->attr('value'));
        self::assertSame('Wireframes', $form->filter('input#title')->attr('value'));
        self::assertSame('title', $form->filter('input#title')->attr('name'));
        self::assertNull($form->filter('input#title')->attr('maxlength'));
        self::assertNull($form->filter('input#title')->attr('autofocus'));
        self::assertNull($form->filter('input#title')->attr('aria-invalid'));
        self::assertSame('/tasks/1', $form->filter('a[data-page-title-cancel]')->attr('href'));
        self::assertCount(0, $form->filter('#title-error'));
    }

    public function testAnOpenTitleEditKeepsWhatWasTypedAndItsError(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'Wireframes', 'titleEdit' => self::TITLE_EDIT + ['open' => true, 'value' => '  ', 'error' => 'Enter the title.', 'maxlength' => 500]]));

        self::assertNotNull($crawler->filter('h1')->attr('hidden'));
        self::assertNotNull($crawler->filter('a[data-page-title-pencil]')->attr('hidden'));
        self::assertNull($crawler->filter('form[data-page-title-form]')->attr('hidden'));
        $field = $crawler->filter('input#title');
        self::assertSame('  ', $field->attr('value'));
        self::assertSame('500', $field->attr('maxlength'));
        self::assertSame('true', $field->attr('aria-invalid'));
        self::assertSame('title-error', $field->attr('aria-describedby'));
        self::assertNull($field->attr('autofocus'));
        self::assertSame('Enter the title.', $crawler->filter('#title-error')->text());
    }

    public function testAnOpenTitleEditWithoutAnErrorIsFocused(): void
    {
        $crawler = new Crawler((string) $this->renderTwigComponent('Ui:PageHeader', ['title' => 'Wireframes', 'titleEdit' => self::TITLE_EDIT + ['open' => true, 'error' => '']]));

        self::assertNotNull($crawler->filter('input#title')->attr('autofocus'));
        self::assertNull($crawler->filter('input#title')->attr('aria-invalid'));
        self::assertCount(0, $crawler->filter('#title-error'));
    }

    public function testTheViewOfATitleEdit(): void
    {
        $header = $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'titleEdit' => self::TITLE_EDIT + ['open' => 'yes', 'value' => 5, 'error' => 5, 'maxlength' => '500']]);
        self::assertInstanceOf(PageHeader::class, $header);

        self::assertSame(['action' => '/tasks/1/title', 'token' => 't0k', 'name' => 'title', 'id' => 'title', 'label' => 'Title', 'href' => '/tasks/1?edit=title', 'cancelHref' => '/tasks/1', 'open' => false, 'value' => 'T', 'error' => null, 'maxlength' => null], $header->titleEditView());
        $plain = $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T']);
        self::assertInstanceOf(PageHeader::class, $plain);
        self::assertNull($plain->titleEditView());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenTitleEdits(): iterable
    {
        foreach (['action', 'token', 'name', 'id', 'label', 'href', 'cancelHref'] as $key) {
            $edit = self::TITLE_EDIT;
            unset($edit[$key]);
            yield 'no '.$key => [$edit];
        }
        yield 'unsafe href' => [['href' => 'javascript:alert(1)'] + self::TITLE_EDIT];
        yield 'unsafe cancel' => [['cancelHref' => 'javascript:alert(1)'] + self::TITLE_EDIT];
    }

    /**
     * @param array<string, mixed> $edit
     */
    #[DataProvider('brokenTitleEdits')]
    public function testABrokenTitleEditThrows(array $edit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:PageHeader', ['title' => 'T', 'titleEdit' => $edit]);
    }
}
