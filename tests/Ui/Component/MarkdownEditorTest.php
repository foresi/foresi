<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Component\ErrorFocus;
use App\Ui\Component\MarkdownEditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class MarkdownEditorTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        self::bootKernel();
        // The preview's CSRF token lives in the session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    public function testRendersTheTextAreaWithTabsAndToolbarHiddenUntilTheControllerConnects(): void
    {
        $crawler = $this->render(['name' => 'body', 'id' => 'comment-body', 'label' => 'Comment', 'value' => 'Hello **world**', 'rows' => 6, 'previewUrl' => '/markdown/preview', 'mentionsUrl' => '/tasks/1/mention-candidates']);

        $textarea = $crawler->filter('textarea#comment-body');
        self::assertCount(1, $textarea);
        self::assertSame('body', $textarea->attr('name'));
        self::assertSame('6', $textarea->attr('rows'));
        self::assertSame('Hello **world**', $textarea->text(normalizeWhitespace: false));
        self::assertSame('comment-body', $crawler->filter('label')->attr('for'));
        self::assertSame('Comment', trim($crawler->filter('label')->text()));
        // The combobox role comes from the controller: without JavaScript it is a plain text area.
        self::assertNull($textarea->attr('role'));

        $root = $crawler->filter('[data-controller="markdown-editor"]');
        self::assertSame('/markdown/preview', $root->attr('data-markdown-editor-preview-url-value'));
        self::assertSame('/tasks/1/mention-candidates', $root->attr('data-markdown-editor-mentions-url-value'));
        self::assertNotEmpty($root->attr('data-markdown-editor-token-value'));

        $bar = $crawler->filter('[data-markdown-editor-bar]');
        self::assertNotNull($bar->attr('hidden'));
        self::assertSame(['Psát', 'Náhled'], $bar->filter('[role="tablist"] [role="tab"]')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame('comment-body-preview', $crawler->filter('#comment-body-preview-tab')->attr('aria-controls'));
        self::assertSame('tabpanel', $crawler->filter('#comment-body-preview')->attr('role'));
        self::assertNotNull($crawler->filter('#comment-body-preview')->attr('hidden'));
        self::assertSame(['Tučně', 'Kurzíva', 'Seznam', 'Odkaz', 'Kód', 'Citace'], $bar->filter('[data-markdown-editor-tool]')->each(static fn (Crawler $button): string => (string) $button->attr('aria-label')));
        self::assertSame('Control+B Meta+B', $crawler->filter('[data-markdown-editor-tool="bold"]')->attr('aria-keyshortcuts'));
        self::assertSame('button', $crawler->filter('[data-markdown-editor-tool="bold"]')->attr('type'));
        self::assertSame('listbox', $crawler->filter('#comment-body-mentions')->attr('role'));
        self::assertStringContainsString('Nikdo neodpovídá', $crawler->filter('[data-markdown-editor-target="empty"]')->text());
    }

    public function testWithoutAMentionsUrlThereIsNoList(): void
    {
        $crawler = $this->render(['name' => 'note', 'label' => 'Note', 'previewUrl' => '/markdown/preview', 'error' => 'Too long']);

        self::assertCount(0, $crawler->filter('[role="listbox"]'));
        self::assertNull($crawler->filter('[data-controller="markdown-editor"]')->attr('data-markdown-editor-mentions-url-value'));
        self::assertSame('true', $crawler->filter('textarea#note')->attr('aria-invalid'));
        self::assertSame('note-error', $crawler->filter('textarea#note')->attr('aria-describedby'));
        self::assertSame('4', $crawler->filter('textarea#note')->attr('rows'));
    }

    public function testCallerActionsAreKeptBesideTheEditorsOwn(): void
    {
        $crawler = $this->render(['name' => 'body', 'label' => 'Comment', 'previewUrl' => '/markdown/preview', 'data-action' => 'keydown->comment-form#shortcut']);

        $action = (string) $crawler->filter('textarea')->attr('data-action');
        self::assertStringContainsString('keydown->markdown-editor#keydown', $action);
        self::assertStringContainsString('keydown->comment-form#shortcut', $action);
    }

    public function testAPreviewUrlIsRequired(): void
    {
        $this->expectException(\Throwable::class);
        $this->render(['name' => 'body', 'label' => 'Comment']);
    }

    /**
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function unsafe(): iterable
    {
        yield 'a script as the preview' => [['previewUrl' => 'javascript:alert(1)']];
        yield 'a script as the people list' => [['mentionsUrl' => 'javascript:alert(1)']];
        yield 'a script as the upload' => [['uploadUrl' => 'javascript:alert(1)', 'uploadKind' => 'task', 'uploadOwner' => 'x']];
        yield 'an upload without the kind' => [['uploadUrl' => '/attachments', 'uploadOwner' => 'x']];
        yield 'an upload without the record' => [['uploadUrl' => '/attachments', 'uploadKind' => 'task']];
        yield 'an upload without both' => [['uploadUrl' => '/attachments']];
    }

    /**
     * @param array<string, string|null> $props
     */
    #[DataProvider('unsafe')]
    public function testUnsafeOrIncompleteUrlsAreRefused(array $props): void
    {
        $editor = new MarkdownEditor(new ErrorFocus());
        $editor->previewUrl = $props['previewUrl'] ?? '/markdown/preview';
        $editor->mentionsUrl = $props['mentionsUrl'] ?? null;
        $editor->uploadUrl = $props['uploadUrl'] ?? null;
        $editor->uploadKind = $props['uploadKind'] ?? null;
        $editor->uploadOwner = $props['uploadOwner'] ?? null;

        $this->expectException(\InvalidArgumentException::class);
        $editor->validateUrls();
    }

    public function testSafeUrlsAndAnUploadWithItsRecordAreAccepted(): void
    {
        $editor = new MarkdownEditor(new ErrorFocus());
        $editor->previewUrl = '/markdown/preview';
        $editor->validateUrls();

        $editor->mentionsUrl = '/mentions';
        $editor->uploadUrl = '/attachments';
        $editor->uploadKind = 'task';
        $editor->uploadOwner = 'abc';
        $editor->validateUrls();

        self::assertSame('/attachments', $editor->uploadUrl);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:MarkdownEditor', $props));
    }
}
