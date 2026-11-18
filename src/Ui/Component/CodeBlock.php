<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Tempest\Highlight\Highlighter;

/**
 * A text to copy as it is: a command, a configuration or a prompt. It is shown as preformatted
 * code, coloured on the server for JSON and shell, with a button that copies it. The pattern is
 * the multi-line code snippet of IBM Carbon (carbondesignsystem.com/components/code-snippet/usage):
 * a read-only block in a monospace font, a copy button in its corner, long lines scrolled
 * sideways rather than wrapped (wrap keeps the soft wrap for prose such as a prompt).
 */
#[AsTwigComponent]
final class CodeBlock
{
    private const array LANGUAGES = ['json', 'bash', 'text'];

    public string $id = '';
    public string $label = '';
    public string $code = '';
    public string $language = 'text';
    public bool $wrap = false;

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id || '' === $this->label) {
            throw new \InvalidArgumentException('A code block needs an id and a label.');
        }
        if (!\in_array($this->language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown code block language "%s"; use one of %s.', $this->language, implode(', ', self::LANGUAGES)));
        }
    }

    /**
     * The code as HTML: escaped, and for JSON and shell wrapped in hl-* spans that app.css
     * colours. Plain text goes through the highlighter's Text language, which only escapes it.
     */
    public function html(): string
    {
        return new Highlighter()->parse($this->code, $this->language);
    }
}
