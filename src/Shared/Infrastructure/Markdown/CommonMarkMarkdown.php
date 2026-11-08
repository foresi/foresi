<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use App\Shared\Application\Markdown;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * CommonMark with the task lists, strikethrough and autolinks of GitHub Flavored
 * Markdown, plus mentions. Raw HTML in the source is escaped; the output then
 * passes an allow-list sanitizer, so a gap in the parser cannot reach the page.
 * Headings start at h3, so a text never outranks the headings of the page it is on.
 */
#[AsAlias(Markdown::class)]
final readonly class CommonMarkMarkdown implements Markdown
{
    /** "#" renders as h3. */
    private const int HEADING_OFFSET = 2;

    private MarkdownConverter $converter;

    private HtmlSanitizerInterface $sanitizer;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'max_delimiters_per_line' => 1000,
            // A line break in the source is a line break on the page, as in GitHub comments.
            'renderer' => ['soft_break' => "<br>\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TaskListExtension());
        $environment->addInlineParser(new MentionParser(), 100);
        $environment->addRenderer(Mention::class, new MentionRenderer());
        $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Heading) {
                    $node->setLevel(min(6, $node->getLevel() + self::HEADING_OFFSET));
                }
            }
        });
        $this->converter = new MarkdownConverter($environment);
        $this->sanitizer = new HtmlSanitizer($this->sanitizerConfig());
    }

    public function toHtml(string $source): string
    {
        if ('' === trim($source)) {
            return '';
        }

        return trim($this->sanitizer->sanitize($this->converter->convert($source)->getContent()));
    }

    private function sanitizerConfig(): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig()
            ->withMaxInputLength(-1)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([])
            ->allowRelativeMedias()
            ->withAttributeSanitizer(new OwnAttachmentImages())
            ->withAttributeSanitizer(new MentionClass())
            ->allowElement('a', ['href'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->allowElement('img', ['src', 'alt'])
            ->allowElement('input', ['type', 'checked', 'disabled'])
            ->forceAttribute('input', 'type', 'checkbox')
            ->forceAttribute('input', 'disabled', 'disabled')
            ->allowElement('span', ['class']);
        foreach (['p', 'br', 'strong', 'em', 'del', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'h3', 'h4', 'h5', 'h6', 'hr'] as $element) {
            $config = $config->allowElement($element);
        }

        return $config;
    }
}
