<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\InvalidMailTemplate;
use App\Invoicing\Domain\MailTemplateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;

/**
 * The texts a firm sends its documents with: its own for a kind and language, or
 * the built-in one. A template of another tenant is never found.
 */
interface MailTemplates
{
    /**
     * The template with its tokens, not filled in.
     */
    public function textFor(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): RenderedMail;

    /**
     * The built-in text of a kind and language, whatever a firm stored.
     */
    public function builtIn(MailTemplateKind $kind, DocumentLanguage $language): RenderedMail;

    /**
     * Whether the firm has its own text, rather than the built-in one.
     */
    public function isCustom(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): bool;

    /**
     * @throws UnknownMailVariables when a token is not a variable, or the subject holds the link
     * @throws InvalidMailTemplate  when the subject or body is empty, too long, or the subject breaks a line
     */
    public function save(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): void;

    /**
     * Back to the built-in text.
     */
    public function reset(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language): void;
}
