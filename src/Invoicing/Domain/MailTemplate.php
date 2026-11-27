<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Shared\Domain\Id;

/**
 * The firm's own subject and body for one kind of mail in one document language.
 * The text holds variables as tokens such as {number}; which ones exist is known
 * to the application, which refuses an unknown one before it gets here. Without a row
 * the firm sends the built-in text.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mail_template')]
#[ORM\UniqueConstraint(name: 'uniq_mail_template_kind_language', columns: ['kind', 'language'])]
class MailTemplate implements Audited
{
    public const int MAX_SUBJECT = 255;
    public const int MAX_BODY = 10000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_SUBJECT)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $body;

    private function __construct(
        #[ORM\Column(type: 'string', length: 16, enumType: MailTemplateKind::class)]
        private readonly MailTemplateKind $kind,
        #[ORM\Column(type: 'string', length: 8, enumType: DocumentLanguage::class)]
        private readonly DocumentLanguage $language,
        string $subject,
        string $body,
    ) {
        $this->id = Id::generate();
        $this->change($subject, $body);
    }

    public static function define(MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): self
    {
        return new self($kind, $language, $subject, $body);
    }

    /**
     * The subject is one line: a line break in it could start a new mail header.
     */
    public function change(string $subject, string $body): void
    {
        $subject = trim($subject);
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ('' === $subject || '' === $body) {
            throw new InvalidMailTemplate('A mail template needs a subject and a body.');
        }
        if (1 === preg_match('/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]/u', $subject)) {
            throw new InvalidMailTemplate('The subject of a mail is one line.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $body)) {
            throw new InvalidMailTemplate('The body of a mail cannot hold a control character.');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            throw new InvalidMailTemplate(\sprintf('A subject takes up to %d characters and a body up to %d.', self::MAX_SUBJECT, self::MAX_BODY));
        }

        $this->subject = $subject;
        $this->body = $body;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): MailTemplateKind
    {
        return $this->kind;
    }

    public function language(): DocumentLanguage
    {
        return $this->language;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }
}
