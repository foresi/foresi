<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\InvalidMailTemplate;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Party\Domain\DocumentLanguage;

/**
 * The texts the firm sends its documents with: its own for a kind and language, or
 * the built-in one.
 */
interface MailTemplates
{
    /**
     * The template with its tokens, not filled in.
     */
    public function textFor(MailTemplateKind $kind, DocumentLanguage $language): RenderedMail;

    /**
     * The built-in text of a kind and language, whatever the firm stored.
     */
    public function builtIn(MailTemplateKind $kind, DocumentLanguage $language): RenderedMail;

    /**
     * Whether the firm has its own text, rather than the built-in one.
     */
    public function isCustom(MailTemplateKind $kind, DocumentLanguage $language): bool;

    /**
     * @throws UnknownMailVariables when a token is not a variable, or the subject holds the link
     * @throws InvalidMailTemplate  when the subject or body is empty, too long, or the subject breaks a line
     */
    public function save(MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): void;

    /**
     * Back to the built-in text.
     */
    public function reset(MailTemplateKind $kind, DocumentLanguage $language): void;
}
